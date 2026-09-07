<?php

/**
 * @file classes/MailFilter.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MailFilter
 *
 * @brief Receives a list of emails and outputs only the ones which passed through the rules, and optionally stores the blocked ones
 */

namespace APP\plugins\generic\mailSendFilter\classes;

use APP\core\Application;
use APP\plugins\generic\mailSendFilter\MailSendFilterPlugin;
use Exception;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PKP\validation\ValidatorFactory;

class MailFilter
{
    public const CACHE_KEY_DISPOSABLE_DOMAINS = self::class . 'disposable.domains';
    public const CACHE_KEY_MX_RECORDS = self::class . 'mx.records';
    public const MX_RECORD_INVALID_EXPIRY_DAYS = 7;
    public const MX_RECORD_VALID_EXPIRY_DAYS = 30;
    private MailSendFilterPlugin $plugin;
    private int $inactivityThresholdDays;
    private bool $checkInactivity;
    private bool $checkMxRecord;
    private bool $checkInvalidEmail;
    private bool $checkDisposable;
    private bool $checkNeverLoggedIn;
    private bool $checkNotValidated;
    /** @var ?array<int,int> */
    private ?array $groupedInactivityThresholdDays = null;
    /** @var ?array{exact:array<string,null>,regex:string[]} */
    private ?array $disposableDomains = null;
    /** @var ?array<string,array{'valid':bool,'expires':int}> */
    private ?array $mxRecords = null;

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
        $checkInvalidEmail = $plugin->getSetting($contextId, 'checkInvalidEmail');
        $this->checkInvalidEmail = $checkInvalidEmail === null ? true : (bool) $checkInvalidEmail;
        $this->checkDisposable = (bool) $plugin->getSetting($contextId, 'checkDisposable');
        $this->checkNeverLoggedIn = (bool) $plugin->getSetting($contextId, 'checkNeverLoggedIn');
        $this->checkNotValidated = (bool) $plugin->getSetting($contextId, 'checkNotValidated');
    }

    /**
     * Retrieves which emails are likely to bounce
     *
     * @param array<string,null> $emails A list of emails, the email is the key
     * @param ?array<string,string> $filteredEmails If passed, will store the filtered emails (key) and the reason (value)
     *
     * @return array<string,null>
     */
    public function filterEmails(array $emails, ?array &$filteredEmails = null): array
    {
        return $this->filterInactiveEmails(
            $this->filterInvalidMailExchanges(
                $this->filterDisposableDomains(
                    $this->filterInvalidEmails($emails, $filteredEmails),
                    $filteredEmails
                ),
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
     * @param ?array<string,string> $filteredEmails If passed, will store the filtered emails (key) and the reason (value)
     *
     * @return array<string,null>
     */
    private function filterInactiveEmails(array $emails, ?array &$filteredEmails = null): array
    {
        if (!$this->checkInactivity && !$this->checkNotValidated && !$this->checkNeverLoggedIn) {
            return $emails;
        }

        $failedEmails = DB::table('users', 'u')
            ->whereIn(DB::raw('LOWER(u.email)'), array_keys($emails))
            // Ignore users which have been registered few time ago
            ->when($this->checkInactivity, fn (Builder $q) =>
                $q->whereRaw($this->dateDiffClause('CURRENT_TIMESTAMP', 'u.date_registered') . ' >= ?', [$this->inactivityThresholdDays])
            )
            ->where(fn (Builder $q) =>
                $q
                    // Not validated accounts
                    ->when($this->checkNotValidated, fn (Builder $q) =>
                        $q->orWhere(fn (Builder $q) =>
                            $q->whereNull('u.date_validated')->whereRaw('COALESCE(u.disabled, 0) = 1')
                        )
                    )
                    // Accounts that have haver logged in
                    ->when($this->checkNeverLoggedIn, fn (Builder $q) => $q->orWhere(fn (Builder $q) =>
                        $q->whereRaw('DATE(u.date_last_login) = DATE(u.date_registered)')
                            ->whereNotExists(fn (Builder $q) => $q->selectRaw('0')
                                ->from('sessions', 's')
                                ->whereColumn('s.user_id', '=', 'u.user_id')
                                ->where('s.last_activity', '>=', time() - 86400)
                            )
                    ))
                    // Accounts which have expired
                    ->when($this->checkInactivity, fn (Builder $q) => $q->orWhereRaw($this->buildRulesQuery()))
            )
            ->selectRaw(
                'LOWER(u.email) AS email,
                CASE
                    WHEN ' . ($this->checkNotValidated ? 'u.date_validated IS NULL AND COALESCE(u.disabled, 0) = 1' : '0 = 1') . " THEN 'notValidated'" . '
                    WHEN ' . ($this->checkNeverLoggedIn ? 'DATE(u.date_last_login) = DATE(u.date_registered) AND NOT EXISTS(
                        SELECT 0
                        FROM sessions s
                        WHERE s.user_id = u.user_id
                        AND s.last_activity >= ' . (time() - 86400) . '
                    )' : '0 = 1') . " THEN 'never_logged'" . '
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
     * Filters out emails which fail OJS email validation
     *
     * @param array<string,null> $emails A list of emails, the email is the key
     * @param array<string,string> $filteredEmails If passed, will store the filtered emails (key) and the reason (value)
     *
     * @return array<string,null>
     */
    private function filterInvalidEmails(array $emails, ?array &$filteredEmails = null): array
    {
        if (!$this->checkInvalidEmail) {
            return $emails;
        }

        foreach (array_keys($emails) as $recipient) {
            $validator = ValidatorFactory::make(
                ['value' => $recipient],
                ['value' => ['required', 'email_or_localhost']]
            );
            if ($validator->fails()) {
                unset($emails[$recipient]);
                if ($filteredEmails !== null) {
                    $filteredEmails[$recipient] = 'invalidEmail';
                }
            }
        }

        return $emails;
    }

    /**
     * Filters out emails which are likely to bounce due to invalid/non-existent mail exchange
     *
     * @param array<string,null> $emails A list of emails, the email is the key
     * @param array<string,string> $filteredEmails If passed, will store the filtered emails (key) and the reason (value)
     *
     * @return array<string, null>
     */
    private function filterInvalidMailExchanges(array $emails, ?array &$filteredEmails = null): array
    {
        if (!$this->checkMxRecord) {
            return $emails;
        }

        // Remove emails which have no MX setup at their domain
        foreach (array_keys($emails) as $recipient) {
            $domain = explode('@', $recipient)[1];
            if (!$this->hasMxRecord($domain)) {
                unset($emails[$recipient]);
                if ($filteredEmails !== null) {
                    $filteredEmails[$recipient] = 'invalidMailExchange';
                }
            }
        }

        return $emails;
    }

    /**
     * Retrieves whether the domain has a MX record
     */
    private function hasMxRecord(string $domain): bool
    {
        $this->mxRecords ??= Cache::get(static::CACHE_KEY_MX_RECORDS, []);
        // Check if we have a valid non-expired cached record
        if (($this->mxRecords[$domain]['expires'] ?? null) > now()->getTimestamp()) {
            return $this->mxRecords[$domain]['valid'];
        }

        // Record doesn't exist or is expired, fetch new data
        $isValid = getmxrr($domain, $hosts);
        $this->mxRecords[$domain] = [
            'valid' => $isValid,
            'expires' => now()
                ->addDays($isValid ? static::MX_RECORD_VALID_EXPIRY_DAYS : static::MX_RECORD_INVALID_EXPIRY_DAYS)
                ->getTimestamp()
        ];
        Cache::put(static::CACHE_KEY_MX_RECORDS, $this->mxRecords);
        return $isValid;
    }

    /**
     * Filters out emails which are likely to belong to a disposable email service
     *
     * @param array<string,null> $emails A list of emails, the email is the key
     * @param array<string,string> $filteredEmails If passed, will store the filtered emails (key) and the reason (value)
     *
     * @return array<string,null>
     */
    private function filterDisposableDomains(array $emails, ?array &$filteredEmails = null): array
    {
        if (!$this->checkDisposable) {
            return $emails;
        }

        $disposableDomains = $this->getDisposableDomains();
        foreach (array_keys($emails) as $recipient) {
            $domain = mb_strtolower(substr(strstr($recipient, '@'), 1));
            if ($this->isDisposableDomain($domain, $disposableDomains)) {
                unset($emails[$recipient]);
                if ($filteredEmails !== null) {
                    $filteredEmails[$recipient] = 'disposableService';
                }
            }
        }

        return $emails;
    }

    /**
     * Checks whether the given domain matches any disposable entry (exact or regex)
     *
     * @param array{exact:array<string,null>,regex:string[]} $disposableDomains
     */
    private function isDisposableDomain(string $domain, array $disposableDomains): bool
    {
        if (array_key_exists($domain, $disposableDomains['exact'])) {
            return true;
        }
        foreach ($disposableDomains['regex'] as $pattern) {
            if (@preg_match($pattern, $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get disposable domains from cache or remote sources
     *
     * Entries starting with "/" are treated as PCRE regular expressions
     * (the user must provide the full pattern including delimiters and flags).
     *
     * @return array{exact:array<string,null>,regex:string[]}
     */
    private function getDisposableDomains(): array
    {
        if ($this->disposableDomains !== null) {
            return $this->disposableDomains;
        }

        $contextId = $this->plugin->getCurrentContextId();
        $expiration = (int) $this->plugin->getSetting($contextId, 'disposableDomainsExpiration') ?: 30;
        $urls = $this->getDisposableDomainsUrls();

        $cacheKey = static::CACHE_KEY_DISPOSABLE_DOMAINS . $expiration . json_encode($urls);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['exact'], $cached['regex'])) {
            return $this->disposableDomains = $cached;
        }

        $exact = [];
        $regex = [];
        $client = Application::get()->getHttpClient();
        foreach ($urls as $url) {
            try {
                $data = $client->get($url)->getBody()->getContents();
            } catch (Exception $e) {
                error_log("Failed to retrieve the list of disposable domains from {$url}.\n" . $e);
                continue;
            }
            foreach (preg_split('/\r\n|\r|\n/', $data) as $line) {
                $entry = trim($line);
                if ($entry === '' || $entry[0] === '#') {
                    continue;
                }
                if ($entry[0] === '/') {
                    $regex[$entry] = null;
                    continue;
                }
                $exact[mb_strtolower($entry)] = null;
            }
        }

        $result = ['exact' => $exact, 'regex' => array_keys($regex)];
        Cache::put($cacheKey, $result, now()->addDays($expiration));
        return $this->disposableDomains = $result;
    }

    /**
     * Retrieves the configured disposable-domain source URLs
     *
     * @return string[]
     */
    private function getDisposableDomainsUrls(): array
    {
        $contextId = $this->plugin->getCurrentContextId();
        $value = $this->plugin->getSetting($contextId, 'disposableDomainsUrls');
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $urls = [];
        foreach ($value as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }

    /**
     * Retrieves a proper date diff clause
     */
    private static function dateDiffClause(string $fieldA, string $fieldB): string
    {
        return match (DB::connection()::class) {
            MySqlConnection::class => "DATEDIFF({$fieldA}, {$fieldB})",
            PostgresConnection::class => "DATE({$fieldA}) - DATE({$fieldB})"
        };
    }
}
