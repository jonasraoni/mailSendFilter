<?php

/**
 * @file classes/SettingsForm.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 */

namespace APP\plugins\generic\mailSendFilter\classes;

use APP\plugins\generic\mailSendFilter\MailSendFilterPlugin;
use Application;
use AppLocale;
use Form;
use FormValidatorCSRF;
use FormValidatorPost;
use NotificationManager;
use TemplateManager;

import('lib.pkp.classes.form.Form');

class SettingsForm extends Form
{
	/** @var MailSendFilterPlugin */
	public $plugin;

	/**
	 * @copydoc Form::__construct
	 */
	public function __construct($plugin)
	{
		AppLocale::requireComponents(LOCALE_COMPONENT_PKP_USER);
		parent::__construct($plugin->getTemplateResource('settings.tpl'));
		$this->plugin = $plugin;
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * @copydoc Form::initData
	 */
	public function initData()
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
		$this->setData('passthroughMailKeys', [AppLocale::getLocale() => json_decode($this->plugin->getSetting($contextId, 'passthroughMailKeys')) ?: []]);
		$this->setData('disposableDomainsUrl', $this->plugin->getSetting($contextId, 'disposableDomainsUrl'));
		$this->setData('disposableDomainsExpiration', (int) $this->plugin->getSetting($contextId, 'disposableDomainsExpiration'));

		parent::initData();
	}

	/**
	 * @copydoc Form::readInputData
	 */
	public function readInputData()
	{
		$vars = ['inactivityThresholdDays', 'checkInactivity', 'checkMxRecord', 'checkDisposable', 'checkNeverLoggedIn', 'checkNotValidated', 'disposableDomainsUrl', 'disposableDomainsExpiration'];
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
	 */
	public function fetch($request, $template = null, $display = false)
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
	public function execute(...$functionArgs)
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
		$this->plugin->updateSetting($contextId, 'disposableDomainsUrl', $this->getData('disposableDomainsUrl'));
		$this->plugin->updateSetting($contextId, 'disposableDomainsExpiration', (int) $this->getData('disposableDomainsExpiration') ?: 30);

		import('classes.notification.NotificationManager');
		$notificationMgr = new NotificationManager();
		$notificationMgr->createTrivialNotification(
			Application::get()->getRequest()->getUser()->getId(),
			NOTIFICATION_TYPE_SUCCESS,
			['contents' => __('common.changesSaved')]
		);

		return parent::execute();
	}

	public static function formatRoleName(string $name): string
	{
		return preg_replace_callback('/\.\w/', function ($matches) {
			return strtoupper(substr($matches[0], 1));
		}, $name);
	}
}
