<?php

/**
 * @file classes/migration/I27_AddCheckInvalidEmail.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class I27_AddCheckInvalidEmail
 *
 * @brief Inserts the checkInvalidEmail setting (enabled).
 */

namespace APP\plugins\generic\mailSendFilter\classes\migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\install\DowngradeNotSupportedException;

class I27_AddCheckInvalidEmail extends Migration
{
    public function up(): void
    {
        DB::table('plugin_settings')->updateOrInsert(
            [
                'context_id' => 0,
                'plugin_name' => 'mailsendfilterplugin',
                'setting_name' => 'checkInvalidEmail',
            ],
            [
                'setting_value' => '1',
                'setting_type' => 'bool',
            ]
        );
    }

    /**
     * @throws DowngradeNotSupportedException
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
