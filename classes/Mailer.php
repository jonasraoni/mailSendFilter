<?php

/**
 * @file classes/Mailer.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Mailer
 *
 * @brief Overrides the PKP's Mailer class with a method to filter emails
 */

namespace APP\plugins\generic\mailSendFilter\classes;

use APP\core\Application;
use APP\core\Request;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use PKP\mail\Mailable;
use ReflectionClass;

class Mailer extends \PKP\mail\Mailer
{
    /** @var array<int, array{subject: string, emails: array<string,string>}> List of invalid deliveries, keyed by the sender's user ID, its sub-values are keyed by the invalid email and the value contains the reason */
    private static array $invalidEmailsByUser = [];
    /** Controls whether the task to create the notifications has been dispatched */
    private static bool $isNotificationDispatched = false;

    public function __construct(string $name, \Symfony\Component\Mailer\Transport\TransportInterface $transport, \Illuminate\Contracts\Events\Dispatcher|null $events = null, private MailFilter $mailFilter, private array $passthroughMailKeys)
    {
        parent::__construct(...func_get_args());
    }

    /**
     * @copydoc IlluminateMailer::send()
     *
     * @param null|mixed $callback
     */
    public function send($view, array $data = [], $callback = null)
    {
        if (!($view instanceof Mailable)) {
            return parent::send($view, $data, $callback);
        }

        $property = (new ReflectionClass($view))->getProperty('emailTemplateKey');
        $property->setAccessible(true);
        if (in_array($property->getValue($view), $this->passthroughMailKeys)) {
            return parent::send($view, $data, $callback);
        }

        $emails = [];
        // Collect all emails
        foreach (array_merge($view->to, $view->cc, $view->bcc) as ['address' => $email]) {
            $emails[mb_strtolower($email)] = null;
        }

        // Filter out the suspicious ones
        /** @var array<string,string> $invalidEmails */
        $invalidEmails = [];
        $emails = $this->mailFilter->filterEmails($emails, $invalidEmails);
        // Stores invalid emails
        static::storeInvalidEmails($invalidEmails, $view);

        $recipients = $this->filterAddresses($view->to, $emails);
        // If there are no recipients, quit sending the email
        if (!count($recipients)) {
            return null;
        }

        $view->to = $recipients;
        $view->cc = $this->filterAddresses($view->cc, $emails);
        $view->bcc = $this->filterAddresses($view->bcc, $emails);

        return parent::send($view, $data, $callback);
    }


    /**
     * Filters out an address list from the Illuminate\Mail\Mailable class using a list of available emails
     *
     * @param array<string,array{'address':string,'name':string}> $addresses
     * @param array<string,null> $availableEmails
     *
     * @return array<string,array{'address':string,'name':string}>
     */
    private function filterAddresses(array $addresses, array $availableEmails): array
    {
        $validEmails = [];
        foreach ($addresses as $address) {
            if (array_key_exists(mb_strtolower($address['address']), $availableEmails)) {
                $validEmails[] = $address;
            }
        }

        return $validEmails;
    }

    /**
     * Stores invalid emails
     *
     * @param array<string,string> $invalidEmails
     */
    private static function storeInvalidEmails(array $invalidEmails, Mailable $view): void
    {
        if (!count($invalidEmails)) {
            return;
        }

        $request = app(Request::class);
        $user = $request->getUser();
        if (!$user) {
            return;
        }

        static::$invalidEmailsByUser[$user->getId()] ??= ['subject' => $view->getSubject(), 'emails' => []];
        static::$invalidEmailsByUser[$user->getId()]['emails'] += $invalidEmails;
        static::dispatchInvalidEmailNotifications();
    }

    /**
     * Dispatches a notification to the user who triggered the email delivery containing the invalid emails
     */
    private static function dispatchInvalidEmailNotifications(): void
    {
        if (static::$isNotificationDispatched) {
            return;
        }

        dispatch(function () {
            $notificationManager = app(NotificationManager::class);
            foreach (static::$invalidEmailsByUser as $userId => $context) {
                $formattedEmails = [];
                foreach ($context['emails'] as $email => $reason) {
                    $formattedEmails[] = "{$email} (" . __("plugins.generic.mailSendFilter.reason.{$reason}") . ")";
                }

                $notificationManager->createNotification(
                    Application::get()->getRequest(),
                    $userId,
                    Notification::NOTIFICATION_TYPE_ERROR,
                    null,
                    null,
                    null,
                    Notification::NOTIFICATION_LEVEL_TASK,
                    ['contents' => __('plugins.generic.mailSendFilter.failedDelivery', ['subject' => $context['subject'], 'emailList' => implode("\n<br>", $formattedEmails)])]
                );
            }
        });
        static::$isNotificationDispatched = true;
    }
}
