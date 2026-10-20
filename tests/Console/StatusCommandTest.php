<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests\Console;

use Aporat\Geo\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

final class StatusCommandTest extends TestCase
{
    public function test_lists_the_databases_on_disk(): void
    {
        config(['geo.maxmind.account_id' => '', 'geo.maxmind.license_key' => '']);

        self::assertSame(0, Artisan::call('geo:status'));
        $output = Artisan::output();

        self::assertStringContainsString('maxmind', $output);
        self::assertStringContainsString('pure PHP', $output);
        self::assertStringContainsString('missing (MAXMIND_ACCOUNT_ID / MAXMIND_LICENSE_KEY)', $output);
        self::assertMatchesRegularExpression('/city\s+\|\s+GeoIP2-City-Test\s+\|\s+GeoIP2-City\s+\|\s+\d{4}-\d{2}-\d{2} \(\d+ days? old\)/', $output);
        self::assertMatchesRegularExpression('/asn\s+\|\s+GeoLite2-ASN-Test\s+\|\s+GeoLite2-ASN\s+\|/', $output);
        self::assertStringNotContainsString('geo:update', $output);
    }

    public function test_points_at_geo_update_when_files_are_missing(): void
    {
        config([
            'geo.maxmind.path' => $this->temporaryDirectory(),
            'geo.maxmind.editions' => ['city' => 'GeoLite2-City', 'country' => null, 'asn' => 'GeoLite2-ASN'],
            'geo.maxmind.account_id' => '123',
            'geo.maxmind.license_key' => 'abc',
        ]);

        self::assertSame(0, Artisan::call('geo:status'));
        $output = Artisan::output();

        self::assertMatchesRegularExpression('/city\s+\|\s+GeoLite2-City\s+\|\s+missing/', $output);
        self::assertMatchesRegularExpression('/country\s+\|\s+—\s+\|\s+unused/', $output);
        self::assertMatchesRegularExpression('/Download credentials \.+ set/', $output);
        self::assertStringContainsString('geo:update', $output);
    }

    public function test_reports_an_unreadable_file(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory.'/GeoLite2-City.mmdb', 'garbage');
        config([
            'geo.maxmind.path' => $directory,
            'geo.maxmind.editions' => ['city' => 'GeoLite2-City', 'country' => null, 'asn' => null],
        ]);

        self::assertSame(0, Artisan::call('geo:status'));

        self::assertStringContainsString('unreadable', Artisan::output());
    }
}
