<?php

/**
 * @file MailSendFilterPlugin.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MailSendFilterPlugin
 *
 * @brief Main plugin class, setups the email override and settings.
 */

namespace APP\plugins\generic\mailSendFilter;

use APP\core\Application;
use APP\notification\NotificationManager;
use APP\plugins\generic\mailSendFilter\classes\MailFilter;
use APP\plugins\generic\mailSendFilter\classes\MailManager;
use APP\plugins\generic\mailSendFilter\classes\SettingsForm;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use SplFileObject;

class MailSendFilterPlugin extends GenericPlugin
{
    // Fake ID for the threshold that deals with users with no roles
    public const THRESHOLD_UNASSIGNED_ROLE = -1;
    // Fake ID for the threshold that deals with users who are assigned to at least one submission
    public const THRESHOLD_ASSIGNED_SUBMISSION = -2;
    /** @var array<string,string> Description map of custom thresholds */
    public array $customThresholds = [
        self::THRESHOLD_UNASSIGNED_ROLE => 'user.role.none',
        self::THRESHOLD_ASSIGNED_SUBMISSION => 'user.with.submission'
    ];
    /** @var string[]|null List of email keys which won't be filtered by the plugin */
    private ?array $passthroughMailKeys = null;

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

        $this->setupMailOverride();
        return $success;
    }

    /**
     * Retrieves the application roles mixed up with the custom thresholds
     *
     * @return array<int,string>
     */
    public function getRoles(): array
    {
        return $this->customThresholds + Application::getRoleNames();
    }

    /**
     * Retrieve the passthrough email keys
     *
     * @return string[]
     */
    public function getPassthroughMailKeys(): array
    {
        return $this->passthroughMailKeys ??= json_decode((string) $this->getSetting($this->getCurrentContextId(), 'passthroughMailKeys')) ?: [];
    }

    /**
     * Setup the mail override
     */
    private function setupMailOverride(): void
    {
        (new MailManager(app(), $this))->register();
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
     * Outputs a CSV file to the browser as an attached file
     */
    private function downloadBlockedEmails(): void
    {
        $filter = new MailFilter($this);
        $context = Application::get()->getRequest()->getContext() ?? null;
        $extractEmail = function (object $row) {
            return [$row->email => null];
        };

        header('content-type: text/plain');
        header('content-disposition: attachment; filename=blocked-emails-' . date('Ymd') . '.csv');
        $output = new SplFileObject('php://output', 'wt');
        //Add BOM (byte order mark) to fix UTF-8 in Excel
        $output->fwrite("\xEF\xBB\xBF");
        $output->fputcsv([__('user.email'), __('grid.user.disableReason')]);
        DB::table('users', 'u')
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
                foreach ($filteredEmails as $email => $reason) {
                    $output->fputcsv([$email, __("plugins.generic.mailSendFilter.reason.{$reason}")]);
                }
            });
    }

    /**
     * Generate a JSONMessage response to display the settings
     */
    private function displaySettings(): JSONMessage
    {
        $form = new SettingsForm($this);
        $request = Application::get()->getRequest();
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

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'settings') {
            return $this->displaySettings();
        }
        if ($request->getUserVar('verb') === 'download') {
            $this->downloadBlockedEmails();
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
     * @copydoc Plugin::isSitePlugin()
     */
    public function isSitePlugin(): bool
    {
        return true;
    }

    /**
     * Overrides to always return the site context
     *
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
