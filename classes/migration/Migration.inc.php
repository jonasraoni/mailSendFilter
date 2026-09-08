<?php

/**
 * @file classes/migration/Migration.inc.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Migration
 * @brief Runs plugin upgrade migrations, skipping ones already recorded in cache.
 */

namespace APP\plugins\generic\mailSendFilter\classes\migration;

use CacheManager;
use Illuminate\Database\Migrations\Migration as IlluminateMigration;
use PKP\install\DowngradeNotSupportedException;

class Migration extends IlluminateMigration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		$cache = CacheManager::getManager()->getFileCache(
			'MailSendFilterPlugin',
			strtr(__METHOD__, ['\\' => '.', '/' => '.', ':' => '.']),
			function () {
				return null;
			}
		);
		$executed = $cache->getContents() ?: [];
		foreach ([
			I19_UpdateDisposableDomainsUrls::class,
			I27_AddCheckInvalidEmail::class,
		] as $class) {
			if ($executed[$class] ?? false) {
				continue;
			}
			(new $class())->up();
			$executed[$class] = true;
			$cache->setEntireCache($executed);
		}
	}

	/**
	 * Down
	 */
	public function down(): void
	{
		throw new DowngradeNotSupportedException();
	}
}
