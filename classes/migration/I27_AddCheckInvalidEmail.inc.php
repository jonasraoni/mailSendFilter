<?php

/**
 * @file classes/migration/I27_AddCheckInvalidEmail.inc.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class I27_AddCheckInvalidEmail
 * @brief Inserts the checkInvalidEmail setting (enabled) when missing.
 */

namespace APP\plugins\generic\mailSendFilter\classes\migration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use PKP\install\DowngradeNotSupportedException;

class I27_AddCheckInvalidEmail extends Migration
{
	/**
	 * Run the migration.
	 */
	public function up(): void
	{
		Capsule::table('plugin_settings')->updateOrInsert(
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
	 * Down
	 */
	public function down(): void
	{
		throw new DowngradeNotSupportedException();
	}
}
