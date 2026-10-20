<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests;

use Aporat\Geo\Facades\Geo;
use Aporat\Geo\GeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

final class ServiceProviderTest extends TestCase
{
    public function test_it_binds_the_manager_as_a_singleton(): void
    {
        self::assertTrue($this->app->bound('geo'));

        $manager = $this->app->make(GeoManager::class);

        self::assertInstanceOf(GeoManager::class, $manager);
        self::assertSame($this->app->make('geo'), $manager);
        self::assertSame($manager, Geo::getFacadeRoot());
    }

    public function test_it_merges_the_default_config(): void
    {
        self::assertSame(['maxmind'], config('geo.drivers'));
        self::assertSame(2.0, config('geo.total_timeout'));
        self::assertSame(['en'], config('geo.maxmind.locales'));
        self::assertStringContainsString('download.maxmind.com', config('geo.maxmind.download_url'));
        self::assertNull(config('geo.queue.connection'));
    }

    public function test_it_registers_the_commands_and_the_request_macro(): void
    {
        self::assertArrayHasKey('geo:update', Artisan::all());
        self::assertArrayHasKey('geo:status', Artisan::all());
        self::assertArrayHasKey('geo:backfill', Artisan::all());
        self::assertTrue(Request::hasMacro('location'));
    }
}
