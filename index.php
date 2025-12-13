<?php

/**
 * @file index.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Wrapper for MailSendFilterPlugin plugin
 */

namespace APP\plugins\generic\mailSendFilter;

require_once 'MailSendFilterPlugin.inc.php';

return new MailSendFilterPlugin();
