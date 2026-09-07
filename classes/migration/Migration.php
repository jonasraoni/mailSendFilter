<?php

/**
 * @file classes/migration/Migration.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Migration
 *
 * @brief Runs plugin upgrade migrations, skipping ones already recorded in cache.
 */

namespace APP\plugins\generic\mailSendFilter\classes\migration;

use Illuminate\Database\Migrations\Migration as IlluminateMigration;
use Illuminate\Support\Facades\Cache;
use PKP\install\DowngradeNotSupportedException;

class Migration extends IlluminateMigration
{
    public function up(): void
    {
        $executed = Cache::get(__METHOD__, []);
        foreach ([
            I19_UpdateDisposableDomainsUrls::class,
            I27_AddCheckInvalidEmail::class,
        ] as $class) {
            if ($executed[$class] ?? false) {
                continue;
            }
            (new $class())->up();
            $executed[$class] = null;
            Cache::forever(__METHOD__, $executed);
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
