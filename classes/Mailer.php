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

use PKP\mail\Mailable;
use ReflectionClass;

class Mailer extends \PKP\mail\Mailer
{
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
        $emails = $this->mailFilter->filterEmails($emails);

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
}
