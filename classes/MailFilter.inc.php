<?php

/**
 * @file classes/MailFilter.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MailFilter
 */

namespace APP\plugins\generic\mailSendFilter\classes;

use APP\plugins\generic\mailSendFilter\MailSendFilterPlugin;
use Application;
use Exception;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Services;

class MailFilter
{
	const DISPOSABLE_DOMAINS_FILENAME = 'disposable-domains';
	/** @var MailSendFilterPlugin */
	private $plugin;
	/** @var int */
	private $inactivityThresholdDays;
	/** @var bool */
	private $checkInactivity;
	/** @var bool */
	private $checkMxRecord;
	/** @var bool */
	private $checkDisposable;
	/** @var bool */
	private $checkNeverLoggedIn;
	/** @var bool */
	private $checkNotValidated;
	/** @var ?array<int,int> */
	private $groupedInactivityThresholdDays = null;
	/** @var ?array<string,null> */
	private $disposableDomains = null;
	/** @var array<string,bool> */
	private $mxRecordByDomain = [];

	/**
	 * Constructor
	 */
	public function __construct(MailSendFilterPlugin $plugin)
	{
		$this->plugin = $plugin;
		$contextId = $plugin->getCurrentContextId();
		$this->inactivityThresholdDays = (int) abs((int) $plugin->getSetting($contextId, 'inactivityThresholdDays'));
		$this->checkInactivity = (bool) $plugin->getSetting($contextId, 'checkInactivity');
		$this->checkMxRecord = (bool) $plugin->getSetting($contextId, 'checkMxRecord');
		$this->checkDisposable = (bool) $plugin->getSetting($contextId, 'checkDisposable');
		$this->checkNeverLoggedIn = (bool) $plugin->getSetting($contextId, 'checkNeverLoggedIn');
		$this->checkNotValidated = (bool) $plugin->getSetting($contextId, 'checkNotValidated');
	}

	/**
	 * Retrieves which emails are likely to bounce
	 *
	 * @param array<string,null> $emails A list of emails, the email is the key
	 * @param array<string,string> $filteredEmails If passed, will store the filtered emails (key) and the reason (value)
	 * @return array<string,null>
	 */
	public function filterEmails(array $emails, array &$filteredEmails = null): array
	{
		return $this->filterInvalidMailExchanges(
			$this->filterInactiveEmails(
				$this->filterDisposableDomains($emails, $filteredEmails),
				$filteredEmails
			),
			$filteredEmails
		);
	}

	/**
	 * Retrieves a list of "threshold IDs" grouped and sorted by the threshold days
	 *
	 * @return array<int,int[]> The key is the threshold day, the value, a list of threshold IDs
	 */
	private function getGroupedInactivityThresholdDays(): array
	{
		if ($this->groupedInactivityThresholdDays !== null) {
			return $this->groupedInactivityThresholdDays;
		}

		$contextId = $this->plugin->getCurrentContextId();
		$roles = $this->plugin->getRoles();
		$inactivityThresholdDaysByRole = [];
		foreach ($roles as $roleId => $roleName) {
			$threshold = $this->plugin->getSetting($contextId, SettingsForm::formatRoleName("threshold.{$roleName}"));
			if (is_numeric($threshold)) {
				$inactivityThresholdDaysByRole[$roleId] = (int) abs((int) $threshold) ?: PHP_INT_MAX;
			}
		}

		$this->groupedInactivityThresholdDays = [];
		foreach ($inactivityThresholdDaysByRole as $roleId => $threshold) {
			$this->groupedInactivityThresholdDays[$threshold][] = $roleId;
		}

		krsort($this->groupedInactivityThresholdDays);

		return $this->groupedInactivityThresholdDays;
	}

	/**
	 * Filters out emails which are likely to bounce due to inactivity
	 *
	 * @param array<string,null> $emails A list of emails, the email is the key
	 * @param array<string,string> $filteredEmails If passed, will store the filtered emails (key) and the reason (value)
	 * @return array<string,null>
	 */
	private function filterInactiveEmails(array $emails, array &$filteredEmails = null): array
	{
		$failedEmails = Manager::table('users', 'u')
			->whereIn('u.email', array_keys($emails))
			// Ignore users which have been registered few time ago
			->when($this->checkInactivity, function (Builder $q) {
				$q->whereRaw($this->dateDiffClause('CURRENT_TIMESTAMP', 'u.date_registered') .' >= ?', [$this->inactivityThresholdDays]);
			})
			->where(function (Builder $q) {
				$q
					// Not validated accounts
					->when($this->checkNotValidated, function (Builder $q) {
						$q->orWhereNull('u.date_validated');
					})
					// Accounts that have haver logged in
					->when($this->checkNeverLoggedIn, function (Builder $q) {
						$q->orWhereRaw('DATE(u.date_last_login) = DATE(u.date_registered)');
					})
					// Accounts which have expired
					->when($this->checkInactivity, function (Builder $q) {
						$q->orWhereRaw($this->buildRulesQuery());
					});
			})
			->selectRaw(
				'LOWER(u.email) AS email,
				CASE
					WHEN ' . ($this->checkNotValidated ? 'u.date_validated IS NULL' : '0 = 1') . " THEN 'notValidated'" . '
					WHEN ' . ($this->checkNeverLoggedIn ? 'DATE(u.date_last_login) = DATE(u.date_registered)' : '0 = 1') . " THEN 'never_logged'" . '
					WHEN ' . ($this->checkInactivity ? $this->buildRulesQuery() : '0 = 1') . " THEN 'inactive'" . '
					WHEN 0 = 1 THEN null
				END AS reason'
			)
			->get();


		// Remove emails which didn't pass the first filter
		foreach ($failedEmails as $email) {
			unset($emails[$email->email]);
			if ($filteredEmails !== null) {
				$filteredEmails[$email->email] = $email->reason;
			}
		}

		return $emails;
	}

	/**
	 * Builds a series of CASE rules, the most lenient rules are places in the top to promote an early return
	 */
	private function buildRulesQuery(): string
	{
		$groupedInactivityThresholdDays = $this->getGroupedInactivityThresholdDays();
		$roleRulesQuery = [];
		foreach ($groupedInactivityThresholdDays as $threshold => $roleIds) {
			$customThresholds = array_intersect($roleIds, array_keys($this->plugin->customThresholds));
			$roleIds = array_diff($roleIds, array_keys($this->plugin->customThresholds));

			$conditions = [];
			if (count($roleIds)) {
				$conditions[] = '
					EXISTS (
						SELECT 0
						FROM user_user_groups AS uug
						INNER JOIN user_groups AS ug
							ON uug.user_group_id = ug.user_group_id
							AND ug.role_id IN (' . implode(', ', $roleIds) . ')
						WHERE
							u.user_id = uug.user_id
					)';
			}

			if (in_array($this->plugin::THRESHOLD_UNASSIGNED_ROLE, $customThresholds)) {
				$conditions[] = '
					NOT EXISTS (
						SELECT 0
						FROM user_user_groups AS uug
						INNER JOIN user_groups AS ug
							ON uug.user_group_id = ug.user_group_id
						WHERE u.user_id = uug.user_id
					)';
			}

			if (in_array($this->plugin::THRESHOLD_ASSIGNED_SUBMISSION, $customThresholds)) {
				$conditions[] = '
					EXISTS (
						SELECT 0
						FROM submissions s
						INNER JOIN stage_assignments sa
							ON sa.submission_id = s.submission_id
						WHERE
							sa.user_id = u.user_id
					)';
			}

			$conditions = count($conditions) ? implode(' OR ', $conditions) : '0 = 1';
			//PHP_INT_MAX refer to the threshold 0, which means never expires. Otherwise, we just check the if the inactivity threshold wasn't reached yet
			$result = $threshold === PHP_INT_MAX ? '0' : 'CASE WHEN ' . $this->dateDiffClause('CURRENT_TIMESTAMP', 'u.date_last_login') . " >= {$threshold} THEN 1 END";
			$roleRulesQuery[] = "WHEN {$conditions} THEN {$result}";
		}
		return count($roleRulesQuery) ? 'CASE ' . implode("\n", $roleRulesQuery) . ' END = 1' : '0 = 1';
	}

	/**
	 * Filters out emails which are likely to bounce due to invalid/non-existent mail exchange
	 *
	 * @param array<string,null> $emails A list of emails, the email is the key
	 * @param array<string,string> $filteredEmails If passed, will store the filtered emails (key) and the reason (value)
	 * @return array<string, null>
	 */
	private function filterInvalidMailExchanges(array $emails, array &$filteredEmails = null): array
	{
		if (!$this->checkMxRecord) {
			return $emails;
		}

		// Remove emails which have no MX setup at their domain
		foreach (array_keys($emails) as $recipient) {
			$domain = substr(strstr($recipient, '@'), 1);
			$isValid = $this->mxRecordByDomain[$domain] = $this->mxRecordByDomain[$domain] ?? getmxrr($domain, $hosts);
			if (!$isValid) {
				unset($emails[$recipient]);
				if ($filteredEmails !== null) {
					$filteredEmails[$recipient] = 'invalidMailExchange';
				}
			}
		}

		return $emails;
	}

	/**
	 * Filters out emails which are likely to belong to a disposable email service
	 *
	 * @param array<string,null> $emails A list of emails, the email is the key
	 * @param array<string,string> $filteredEmails If passed, will store the filtered emails (key) and the reason (value)
	 * @return array<string,null>
	 */
	private function filterDisposableDomains(array $emails, array &$filteredEmails = null): array
	{
		if (!$this->checkDisposable) {
			return $emails;
		}

		/**
		 * @var \PKP\Services\PKPFileService $fileService
		 */
		$fileService = Services::get('file');
		if ($this->disposableDomains === null) {
			$this->disposableDomains = [];
			$path = "{$this->plugin->getDirName()}/" . static::DISPOSABLE_DOMAINS_FILENAME;
			$oneDay = 60 * 60 * 24;
			$expiration = (int) $this->plugin->getSetting($this->plugin->getCurrentContextId(), 'disposableDomainsExpiration') ?: 30;
			if (!$fileService->fs->has($path) || time() - $fileService->fs->getTimestamp($path) > $oneDay * $expiration) {
				try {
					$disposableDomainsUrl = $this->plugin->getSetting($this->plugin->getCurrentContextId(), 'disposableDomainsUrl');
					$data = $disposableDomainsUrl ? Application::get()->getHttpClient()->get($disposableDomainsUrl)->getBody()->getContents() : '';
					$fileService->fs->put($path, mb_strtolower($data));
				} catch (Exception $e) {
					error_log("Failed to retrieve the list of disposable domains.\n" . $e);
				}
			}

			foreach (preg_split('/\r\n|\n\r|\r|\n/', $fileService->fs->read($path)) as $domain) {
				$this->disposableDomains[$domain] = null;
			}
		}

		foreach (array_keys($emails) as $recipient) {
			$domain = substr(strstr($recipient, '@'), 1);
			if (array_key_exists($domain, $this->disposableDomains)) {
				unset($emails[$recipient]);
				if ($filteredEmails !== null) {
					$filteredEmails[$recipient] = 'disposableService';
				}
			}
		}

		return $emails;
	}

	/**
	 * Retrieves a proper date diff clause
	 */
	private static function dateDiffClause(string $fieldA, string $fieldB): string
	{
		switch (get_class(Manager::connection())) {
			case MySqlConnection::class:
				return "DATEDIFF({$fieldA}, {$fieldB})";
			case PostgresConnection::class:
				return "DATE({$fieldA}) - DATE({$fieldB})";
			default:
				throw new Exception('Unknown database');
		}
	}
}
