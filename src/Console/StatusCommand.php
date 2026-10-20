<?php

declare(strict_types=1);

namespace Aporat\Geo\Console;

use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\Drivers\MaxMindDriver;
use Aporat\Geo\Exceptions\DatabaseException;
use Aporat\Geo\GeoManager;
use Aporat\Geo\Support\Config;
use Illuminate\Console\Command;

/**
 * What is on disk, how old it is, and how it is read.
 */
class StatusCommand extends Command
{
    protected $signature = 'geo:status';

    protected $description = 'Show the driver chain and the MaxMind databases on disk';

    public function handle(GeoManager $geo): int
    {
        $this->components->twoColumnDetail('Driver chain', implode(' → ', array_map(static fn (Driver $driver): string => $driver->name(), $geo->drivers())));

        $driver = $geo->driver('maxmind');
        if (! $driver instanceof MaxMindDriver) {
            $this->components->twoColumnDetail('MaxMind', 'not the package driver');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Reader', MaxMindDriver::usesExtension() ? 'ext-maxminddb (C)' : 'pure PHP (install ext-maxminddb for faster lookups)');
        $this->components->twoColumnDetail('Directory', $driver->directory());
        $credentials = Config::string(config('geo.maxmind.account_id')) !== '' && Config::string(config('geo.maxmind.license_key')) !== '';
        $this->components->twoColumnDetail('Download credentials', $credentials ? 'set' : 'missing (MAXMIND_ACCOUNT_ID / MAXMIND_LICENSE_KEY)');
        $this->newLine();

        $rows = [];
        $missing = false;
        foreach ([MaxMindDriver::ROLE_CITY, MaxMindDriver::ROLE_COUNTRY, MaxMindDriver::ROLE_ASN] as $role) {
            $edition = $driver->edition($role);
            if ($edition === null) {
                $rows[] = [$role, '—', 'unused', '', ''];

                continue;
            }

            $path = (string) $driver->databasePath($role);
            if (! is_file($path)) {
                $missing = true;
                $rows[] = [$role, $edition, 'missing', '', ''];

                continue;
            }

            try {
                $metadata = $driver->metadata($role);
            } catch (DatabaseException $e) {
                $rows[] = [$role, $edition, 'unreadable: '.$e->getMessage(), '', ''];

                continue;
            }
            if ($metadata === null) {
                $missing = true;
                $rows[] = [$role, $edition, 'missing', '', ''];

                continue;
            }

            $epoch = $metadata->buildEpoch;
            $age = max(0, intdiv(time() - $epoch, 86400));
            $rows[] = [
                $role,
                $edition,
                $metadata->databaseType,
                date('Y-m-d', $epoch).sprintf(' (%d day%s old)', $age, $age === 1 ? '' : 's'),
                number_format((int) filesize($path) / 1024 / 1024, 1).' MB',
            ];
        }

        $this->table(['Role', 'Edition', 'Status', 'Built', 'Size'], $rows);

        if ($missing) {
            $this->components->warn('Run `php artisan geo:update` to download the missing databases.');
        }

        return self::SUCCESS;
    }
}
