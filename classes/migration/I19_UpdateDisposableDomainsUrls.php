<?php

/**
 * @file classes/migration/I19_UpdateDisposableDomainsUrls.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 */

namespace APP\plugins\generic\mailSendFilter\classes\migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\install\DowngradeNotSupportedException;

class I19_UpdateDisposableDomainsUrls extends Migration
{
    private const PLUGIN_NAME = 'mailsendfilterplugin';
    private const LEGACY_SETTING = 'disposableDomainsUrl';
    private const NEW_SETTING = 'disposableDomainsUrls';
    private const LEGACY_DEFAULT_URL = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/refs/heads/main/disposable_email_blocklist.conf';
    private const NEW_DEFAULT_URL = 'https://raw.githubusercontent.com/alexandrosmagos/dyn-dns-list/refs/heads/master/links.txt';

    public function up(): void
    {
        $rows = DB::table('plugin_settings')
            ->where('plugin_name', self::PLUGIN_NAME)
            ->where('setting_name', self::LEGACY_SETTING)
            ->get();

        foreach ($rows as $row) {
            $legacy = trim((string) $row->setting_value);
            $urls = $legacy !== '' ? [$legacy] : [];

            if (in_array(self::LEGACY_DEFAULT_URL, $urls, true) && !in_array(self::NEW_DEFAULT_URL, $urls, true)) {
                $urls[] = self::NEW_DEFAULT_URL;
            }

            $urls = array_values(array_unique($urls));

            DB::table('plugin_settings')->updateOrInsert(
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

            DB::table('plugin_settings')
                ->where('context_id', (int) $row->context_id)
                ->where('plugin_name', self::PLUGIN_NAME)
                ->where('setting_name', self::LEGACY_SETTING)
                ->delete();
        }
    }

    /**
     * @throws DowngradeNotSupportedException
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
