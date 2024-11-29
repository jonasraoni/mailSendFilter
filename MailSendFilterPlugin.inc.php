<?php

/**
 * @file MailSendFilterPlugin.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 *
 * @class MailSendFilterPlugin
 */

namespace APP\plugins\generic\mailSendFilter;

use AjaxModal;
use APP\plugins\generic\mailSendFilter\classes\MailFilter;
use APP\plugins\generic\mailSendFilter\classes\SettingsForm;
use Application;
use HookRegistry;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use JSONMessage;
use LinkAction;
use GenericPlugin;
use Mail;
use MailTemplate;
use NotificationManager;
use RedirectAction;
use SplFileObject;

class MailSendFilterPlugin extends GenericPlugin
{
	public const THRESHOLD_UNASSIGNED_ROLE = -1;
	public const THRESHOLD_ASSIGNED_SUBMISSION = -2;
	/** @var array<string,string> */
	public $customThresholds = [
		self::THRESHOLD_UNASSIGNED_ROLE => 'user.role.none',
		self::THRESHOLD_ASSIGNED_SUBMISSION => 'user.with.submission'
	];
	/** @var string[] */
	private $passthroughKeys;

	/**
	 * @copydoc Plugin::register
	 *
	 * @param null|int $mainContextId
	 */
	public function register($category, $path, $mainContextId = null): bool
	{
		$success = parent::register($category, $path, $mainContextId);
		if (!$success || !$this->getEnabled()) {
			return $success;
		}

		$this->useAutoLoader();
		$this->setupMailOverride();
		$this->passthroughKeys = json_decode($this->getSetting($this->getCurrentContextId(), 'passthroughKeys')) ?: [];
		return $success;
	}

	/**
	 * Registers a custom autoloader to handle the plugin namespace
	 */
	private function useAutoLoader(): void
	{
		spl_autoload_register(function ($className) {
			// Removes the base namespace from the class name
			$path = explode(__NAMESPACE__ . '\\', $className, 2);
			if (!reset($path)) {
				// Breaks the remaining class name by \ to retrieve the folder and class name
				$path = explode('\\', end($path));
				$class = array_pop($path);
				$path = array_map(function ($name) {
					return strtolower($name[0]) . substr($name, 1);
				}, $path);
				$path[] = $class;
				// Uses the internal loader
				$this->import(implode('.', $path));
			}
		});
	}

	/**
	 * Retrieves the roles
	 *
	 * @return array<int,string>
	 */
	public function getRoles(): array
	{
		return $this->customThresholds + Application::getRoleNames();
	}

	/**
	 * Filters out an address list from the mail object based on the list of available emails
	 *
	 * @param array<string,array{'email':string,'name':string}> $addresses
	 * @param array<string,null> $availableEmails
	 * @return array<string,array{'email':string,'name':string}>
	 */
	private function filterAddresses(array $addresses, array $availableEmails): array
	{
		$validEmails = [];
		foreach ($addresses as $address) {
			if (array_key_exists(mb_strtolower($address['email']), $availableEmails)) {
				$validEmails[] = $address;
			}
		}

		return $validEmails;
	}

	/**
	 * Setup the mail override
	 */
	private function setupMailOverride(): void
	{
		$filter = new MailFilter($this);
		HookRegistry::register('Mail::send', function (string $hookName, array $args) use ($filter): bool {
			[$mail] = $args;

			if ($mail instanceof MailTemplate && in_array($mail->emailKey, $this->passthroughKeys)) {
				return false;
			}

			/** @var Mail $mail */
			$emails = [];
			// Collect all emails
			foreach (array_merge($mail->getRecipients() ?? [], $mail->getCcs() ?? [], $mail->getBccs() ?? []) as ['email' => $email]) {
				$emails[mb_strtolower($email)] = null;
			}

			// Filter out the suspicious ones
			$emails = $filter->filterEmails($emails);

			$recipients = $this->filterAddresses($mail->getRecipients(), $emails);
			// If there are no recipients, quit sending the email
			if (!count($recipients)) {
				return true;
			}

			$mail->setRecipients($recipients);
			$mail->setCcs($this->filterAddresses($mail->getCcs(), $emails));
			$mail->setBccs($this->filterAddresses($mail->getBccs(), $emails));

			return false;
		});
	}

    /**
     * Setup the hook to download the changes
     */
    public function setupDownloadChangesEndpoint(): void
    {
        Hook::add('LoadHandler', function (string $hookName, array $args): bool {
            $request = $this->getRequest();
            // Get url path components by reference
            [&$page, &$op] = $args;
            $tail = implode('/', $request->getRequestedArgs());

            if ([$page, $op, $tail] === ['management', 'settings', 'printCustomLocaleChanges']) {
                $op = 'printCustomLocaleChanges';
                define('HANDLER_CLASS', CustomLocaleHandler::class);
            }

            return false;
        });
    }

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $actionArgs): array
	{
		$actions = parent::getActions($request, $actionArgs);
		if (!$this->getEnabled()) {
			return $actions;
		}

		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.RedirectAction');
		array_unshift(
			$actions,
			new LinkAction(
				'settings',
				new AjaxModal($router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']), $this->getDisplayName()),
				__('manager.plugins.settings'),
				null
			),
			new LinkAction(
				'downloadEmails',
				new RedirectAction($router->url($request, null, null, 'manage', null, ['verb' => 'download', 'plugin' => $this->getName(), 'category' => 'generic'])),
				__('plugins.generic.mailSendFilter.downloadEmails')
			)
		);
		return $actions;
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	public function manage($args, $request)
	{
		if ($request->getUserVar('verb') === 'settings') {
			$form = new SettingsForm($this);

			if ($request->getUserVar('save')) {
				$form->readInputData();
				if ($form->validate()) {
					$form->execute();
					$notificationManager = new NotificationManager();
					$notificationManager->createTrivialNotification($request->getUser()->getId());
					return new JSONMessage(true);
				}
			} else {
				$form->initData();
			}
			return new JSONMessage(true, $form->fetch($request));
		}
		if ($request->getUserVar('verb') === 'download') {
			$filter = new MailFilter($this);
			$context = Application::get()->getRequest()->getContext() ?? null;
			$extractEmail = function (object $row) {
				return [$row->email => null];
			};

			header('content-type: text/comma-separated-values');
			header('content-disposition: attachment; filename=blocked-emails-' . date('Ymd') . '.csv');
			$output = new SplFileObject('php://output', 'wt');
			//Add BOM (byte order mark) to fix UTF-8 in Excel
			$output->fwrite("\xEF\xBB\xBF");
			$output->fputcsv([__('user.email'), __('grid.user.disableReason')]);
			Manager::table('users', 'u')
				->when($context, function (Builder $q) {
					$q->whereExists(function (Builder $q) {
						$q->from('user_user_groups', 'uug')
							->join('user_groups AS ug', 'ug.user_group_id', '=', 'uug.user_group_id')
							->whereColumn('uug.user_id', '=', 'u.user_id');
					});
				})
				->select('u.email')
				->orderBy('u.user_id')
				->chunk(1000, function (Collection $rows) use ($extractEmail, $filter, $output) {
					$emails = $rows->mapWithKeys($extractEmail);
					$filteredEmails = [];
					$filter->filterEmails($emails->all(), $filteredEmails);
					foreach($filteredEmails as $email => $reason) {
						$output->fputcsv([$email, __("plugins.generic.mailSendFilter.reason.{$reason}")]);
					}
				});
			exit;
		}
		return parent::manage($args, $request);
	}

	/**
	 * @copydoc Plugin::getName()
	 */
	public function getName(): string
	{
		$class = explode('\\', __CLASS__);
		return end($class);
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName(): string
	{
		return __('plugins.generic.mailSendFilter.name');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription(): string
	{
		return __('plugins.generic.mailSendFilter.description');
	}

	/**
	 * Load the plugin before others
	 *
	 * @copydoc Plugin::getSeq()
	 */
	public function getSeq(): int
	{
		return -1;
	}

	/**
	 * Avoid disabling the plugin
	 *
	 * @copydoc Plugin::setEnabled()
	 */
	public function setEnabled($enabled): void
	{
		parent::setEnabled(true);
	}

	/**
	 * @copydoc Plugin::isSitePlugin()
	 */
	public function isSitePlugin(): bool
	{
		return true;
	}

	/**
	 * Overrides to always return the site context
	 * @copydoc Plugin::getCurrentContextId(()
	 */
	public function getCurrentContextId(): int
	{
		return 0;
	}

	/**
	 * @copydoc Plugin::getInstallSitePluginSettingsFile()
	 */
	public function getInstallSitePluginSettingsFile(): string
	{
		return $this->getPluginPath() . '/settings.xml';
	}
}