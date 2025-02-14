<?php

/**
 * @file classes/MailManager.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MailManager
 *
 * @brief Overrides the PKP's MailManager to create a proper Mailer instance
 */

namespace APP\plugins\generic\mailSendFilter\classes;

use APP\plugins\generic\mailSendFilter\MailSendFilterPlugin;
use InvalidArgumentException;
use PKP\mail\transport\PHPMailerTransport;
use Symfony\Component\Mailer\Transport\SendmailTransport;

class MailManager extends \Illuminate\Mail\MailManager
{
    public function __construct($app, private MailSendFilterPlugin $plugin) {
        parent::__construct(...func_get_args());
    }

    /**
     * Overwrites the current mail.manager and mailer at the container
     */
    public function register(): void
    {
        $this->app->singleton('mail.manager', fn () => $this);
        $this->app->bind('mailer', fn () => $this->app->make('mail.manager')->mailer());
    }

    /**
     * @see MailManager::resolve()
     *
     * @param string $name
     *
     * @throws InvalidArgumentException
     */
    protected function resolve($name): Mailer
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Mailer [{$name}] is not defined.");
        }

        // Override Illuminate mailer construction to remove unsupported view
        $mailer = new Mailer(
            $name,
            $this->createSymfonyTransport($config),
            $this->app['events'],
            new MailFilter($this->plugin),
            $this->plugin->getPassthroughMailKeys()
        );

        if ($this->app->bound('queue')) {
            $mailer->setQueue($this->app['queue']);
        }

        return $mailer;
    }

    /*
    * Override sendmail transport construction to allow default path
    */
    protected function createSendmailTransport(array $config): SendmailTransport
    {
        $path = $config['path'] ?? $this->app['config']->get('mail.sendmail');
        return $path ? new SendmailTransport($path) : new SendmailTransport();
    }

    /**
     * Transport to send with mail() function by PHPMailer
     */
    protected function createPHPMailerTransport(): PHPMailerTransport
    {
        return new PHPMailerTransport();
    }
}
