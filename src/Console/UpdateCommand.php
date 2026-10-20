<?php

declare(strict_types=1);

namespace Aporat\Geo\Console;

use Aporat\Geo\Drivers\MaxMindDriver;
use Aporat\Geo\Exceptions\DownloadException;
use Aporat\Geo\GeoManager;
use Aporat\Geo\Support\Config;
use Aporat\Geo\Support\Downloader;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Download the configured MaxMind editions with the account's license.
 */
class UpdateCommand extends Command
{
    protected $signature = 'geo:update
        {--edition=* : Only these edition ids (default: every edition in geo.maxmind.editions)}';

    protected $description = 'Download the MaxMind databases the maxmind driver reads';

    public function handle(GeoManager $geo, HttpFactory $http): int
    {
        $driver = $geo->driver('maxmind');
        if (! $driver instanceof MaxMindDriver) {
            $this->error('The maxmind driver is not the package\'s MaxMindDriver; nothing to update.');

            return self::FAILURE;
        }

        $accountId = Config::string(config('geo.maxmind.account_id'));
        $licenseKey = Config::string(config('geo.maxmind.license_key'));
        if ($accountId === '' || $licenseKey === '') {
            $this->error('Set MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY (geo.maxmind.account_id / license_key) first.');

            return self::FAILURE;
        }

        /** @var list<string> $requested */
        $requested = array_values(array_filter((array) $this->option('edition'), is_string(...)));
        $editions = $requested === [] ? $driver->editions() : $requested;
        if ($editions === []) {
            $this->warn('No editions configured in geo.maxmind.editions.');

            return self::SUCCESS;
        }

        $downloader = new Downloader(
            http: $http,
            accountId: $accountId,
            licenseKey: $licenseKey,
            urlTemplate: Config::string(config('geo.maxmind.download_url'), 'https://download.maxmind.com/geoip/databases/{edition}/download?suffix=tar.gz'),
            timeout: Config::int(config('geo.maxmind.download_timeout'), 300),
        );

        $rows = [];
        $failed = false;
        foreach ($editions as $edition) {
            $this->components->task(sprintf('Downloading %s', $edition), function () use ($downloader, $driver, $edition, &$rows, &$failed): bool {
                try {
                    $result = $downloader->download($edition, $driver->directory());
                } catch (DownloadException $e) {
                    $failed = true;
                    $rows[] = [$edition, 'failed', $e->getMessage(), ''];

                    return false;
                }

                $rows[] = [
                    $edition,
                    $result['database_type'],
                    date('Y-m-d H:i', $result['build_epoch']).' UTC',
                    number_format($result['bytes'] / 1024 / 1024, 1).' MB',
                ];

                return true;
            });
        }

        $driver->reset();
        $geo->flush();

        $this->table(['Edition', 'Type', 'Built', 'Size'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
