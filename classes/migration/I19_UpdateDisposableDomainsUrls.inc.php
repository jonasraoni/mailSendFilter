<?php

/**
 * @file classes/migration/I19_UpdateDisposableDomainsUrls.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MailSendFilterSchemaMigration
 * @brief Reconciles existing settings on plugin upgrade.
 *
 * - Migrates the legacy single-URL `disposableDomainsUrl` setting into the new
 *   array-typed `disposableDomainsUrls` setting.
 * - When the legacy value is the original default URL, also seeds the new
 *   dyn-dns-list source so existing installations get the additional list for
 *   free.
 */

namespace APP\plugins\generic\mailSendFilter\classes\migration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use PKP\install\DowngradeNotSupportedException;

class I19_UpdateDisposableDomainsUrls extends Migration
{
	private const LEGACY_DEFAULT_URL = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/refs/heads/main/disposable_email_blocklist.conf';
	private const NEW_DEFAULT_URL = 'https://raw.githubusercontent.com/alexandrosmagos/dyn-dns-list/refs/heads/master/links.txt';
	private const LEGACY_SETTING = 'disposableDomainsUrl';
	private const NEW_SETTING = 'disposableDomainsUrls';
	private const PLUGIN_NAME = 'mailsendfilterplugin';

	/**
	 * Run the migration. Safe to re-run: only acts when the legacy key is present.
	 */
	public function up(): void
	{
		$rows = Capsule::table('plugin_settings')
			->where('plugin_name', self::PLUGIN_NAME)
			->where('setting_name', self::LEGACY_SETTING)
			->get();

		foreach ($rows as $row) {
			$legacy = trim((string) $row->setting_value);
			$urls = $legacy !== '' ? [$legacy] : [];

			// Existing installs running the original default also get the new dyn-dns list.
			if (in_array(self::LEGACY_DEFAULT_URL, $urls, true) && !in_array(self::NEW_DEFAULT_URL, $urls, true)) {
				$urls[] = self::NEW_DEFAULT_URL;
			}

			$urls = array_values(array_unique($urls));

			Capsule::table('plugin_settings')
				->updateOrInsert(
					[
						'context_id' => (int) $row->context_id,
						'plugin_name' => self::PLUGIN_NAME,
						'setting_name' => self::NEW_SETTING,
					],
					[
						'setting_value' => json_encode($urls, JSON_UNESCAPED_UNICODE),
						'setting_type' => 'object',
					]
				);

			Capsule::table('plugin_settings')
				->where('context_id', (int) $row->context_id)
				->where('plugin_name', self::PLUGIN_NAME)
				->where('setting_name', self::LEGACY_SETTING)
				->delete();
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
