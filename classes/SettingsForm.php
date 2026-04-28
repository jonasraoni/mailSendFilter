<?php

/**
 * @file classes/SettingsForm.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 */

namespace APP\plugins\generic\mailSendFilter\classes;

use APP\core\Application;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\plugins\generic\mailSendFilter\MailSendFilterPlugin;
use APP\template\TemplateManager;
use PKP\facades\Locale;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class SettingsForm extends Form
{
    /**
     * @copydoc Form::__construct
     */
    public function __construct(public MailSendFilterPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData
     */
    public function initData(): void
    {
        $contextId = $this->plugin->getCurrentContextId();
        foreach ($this->plugin->getRoles() as $roleName) {
            $setting = $this->formatRoleName("threshold.{$roleName}");
            $value = (string) $this->plugin->getSetting($contextId, $setting);
            $this->setData($setting, strlen($value) ? (int) $value : '');
        }
        $this->setData('inactivityThresholdDays', (int) $this->plugin->getSetting($contextId, 'inactivityThresholdDays'));
        $this->setData('checkInactivity', (bool) $this->plugin->getSetting($contextId, 'checkInactivity'));
        $this->setData('checkMxRecord', (bool) $this->plugin->getSetting($contextId, 'checkMxRecord'));
        $this->setData('checkDisposable', (bool) $this->plugin->getSetting($contextId, 'checkDisposable'));
        $this->setData('checkNeverLoggedIn', (bool) $this->plugin->getSetting($contextId, 'checkNeverLoggedIn'));
        $this->setData('checkNotValidated', (bool) $this->plugin->getSetting($contextId, 'checkNotValidated'));
        $this->setData('passthroughMailKeys', [Locale::getLocale() => json_decode((string) $this->plugin->getSetting($contextId, 'passthroughMailKeys')) ?: []]);
        $this->setData('disposableDomainsUrls', implode("\n", $this->getDisposableDomainsUrls($contextId)));
        $this->setData('disposableDomainsExpiration', (int) $this->plugin->getSetting($contextId, 'disposableDomainsExpiration'));

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData
     */
    public function readInputData(): void
    {
        $vars = ['inactivityThresholdDays', 'checkInactivity', 'checkMxRecord', 'checkDisposable', 'checkNeverLoggedIn', 'checkNotValidated', 'disposableDomainsUrls', 'disposableDomainsExpiration'];
        foreach ($this->plugin->getRoles() as $roleName) {
            $vars[] = $this->formatRoleName("threshold.{$roleName}");
        }

        $request = Application::get()->getRequest();
        $this->setData('passthroughMailKeys', $request->getUserVar('keywords')['passthroughMailKeys'] ?: []);

        $this->readUserVars($vars);
        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $roles = [];
        foreach ($this->plugin->getRoles() as $roleName) {
            $setting = $this->formatRoleName("threshold.{$roleName}");
            $roles[] = [
                'name' => $setting,
                'value' => $this->getData($setting),
                'label' => $roleName
            ];
        }

        $templateManager = TemplateManager::getManager($request);
        $templateManager->assign([
            'pluginName' => $this->plugin->getName(),
            'roles' => $roles
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute
     */
    public function execute(...$functionArgs): mixed
    {
        $contextId = $this->plugin->getCurrentContextId();
        foreach ($this->plugin->getRoles() as $roleName) {
            $setting = $this->formatRoleName("threshold.{$roleName}");
            $value = (string) $this->getData($setting);
            $this->plugin->updateSetting($contextId, $setting, strlen($value) ? (int) $value : '');
        }
        $this->plugin->updateSetting($contextId, 'inactivityThresholdDays', (int) $this->getData('inactivityThresholdDays'));
        $this->plugin->updateSetting($contextId, 'checkInactivity', (bool) $this->getData('checkInactivity'), 'bool');
        $this->plugin->updateSetting($contextId, 'checkMxRecord', (bool) $this->getData('checkMxRecord'), 'bool');
        $this->plugin->updateSetting($contextId, 'checkDisposable', (bool) $this->getData('checkDisposable'), 'bool');
        $this->plugin->updateSetting($contextId, 'checkNeverLoggedIn', (bool) $this->getData('checkNeverLoggedIn'), 'bool');
        $this->plugin->updateSetting($contextId, 'checkNotValidated', (bool) $this->getData('checkNotValidated'), 'bool');
        $this->plugin->updateSetting($contextId, 'passthroughMailKeys', json_encode($this->getData('passthroughMailKeys')));
        $urls = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $this->getData('disposableDomainsUrls')) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $urls[] = $line;
            }
        }
        $this->plugin->updateSetting($contextId, 'disposableDomainsUrls', array_values(array_unique($urls)), 'object');
        $this->plugin->updateSetting($contextId, 'disposableDomainsExpiration', (int) $this->getData('disposableDomainsExpiration') ?: 30);

        $notificationMgr = new NotificationManager();
        $notificationMgr->createTrivialNotification(
            Application::get()->getRequest()->getUser()->getId(),
            Notification::NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('common.changesSaved')]
        );

        return parent::execute();
    }

    /**
     * Transforms "a.bc.de" into "aBcDe"
     */
    public static function formatRoleName(string $name): string
    {
        return preg_replace_callback('/\.\w/', fn (array $matches): string => strtoupper(substr($matches[0], 1)), $name);
    }

    /**
     * Retrieves the disposable-domain source URLs, tolerating legacy string values
     *
     * @return string[]
     */
    private function getDisposableDomainsUrls(int $contextId): array
    {
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
}
