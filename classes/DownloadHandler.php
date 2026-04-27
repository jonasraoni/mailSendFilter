<?php

/**
 * @file DownloadHandler.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DownloadHandler
 * @brief Serves the per-notification CSV of failed email recipients.
 */


namespace APP\plugins\generic\mailSendFilter\classes;

use APP\core\Request;
use APP\handler\Handler;
use PKP\db\DAORegistry;
use PKP\notification\NotificationDAO;
use PKP\notification\NotificationSettingsDAO;
use PKP\security\authorization\PKPSiteAccessPolicy;
use SplFileObject;

class DownloadHandler extends Handler
{
    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments): bool
    {
        $this->addPolicy(new PKPSiteAccessPolicy($request, ['downloadFailedEmails'], PKPSiteAccessPolicy::SITE_ACCESS_ALL_ROLES));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Stream a CSV listing the failed recipients of the given notification.
     */
    public function downloadFailedEmails(array $args, Request $request): void
    {
        $user = $request->getUser();
        $notificationId = (int) array_shift($args);
        if (!$user || !$notificationId) {
            $request->getDispatcher()->handle404();
            return;
        }

        /** @var NotificationDAO $notificationDao */
        $notificationDao = DAORegistry::getDAO('NotificationDAO');
        $notification = $notificationDao->getById($notificationId, $user->getId());
        if (!$notification) {
            $request->getDispatcher()->handle404();
            return;
        }

        /** @var NotificationSettingsDAO $notificationSettingsDao */
        $notificationSettingsDao = DAORegistry::getDAO('NotificationSettingsDAO');
        $settings = $notificationSettingsDao->getNotificationSettings($notificationId);
        $emails = !empty($settings['mailSendFilterEmailsJson']) ? json_decode($settings['mailSendFilterEmailsJson'], true) : [];
        if (!is_array($emails)) {
            $emails = [];
        }

        header('content-type: text/plain');
        header('content-disposition: attachment; filename=failed-emails-' . $notificationId . '-' . date('Ymd') . '.csv');
        $output = new SplFileObject('php://output', 'wt');
        // BOM so Excel detects UTF-8
        $output->fwrite("\xEF\xBB\xBF");
        $output->fputcsv([__('user.email'), __('grid.user.disableReason')]);
        foreach ($emails as $email => $reason) {
            $output->fputcsv([$email, __("plugins.generic.mailSendFilter.reason.{$reason}")]);
        }
    }
}
