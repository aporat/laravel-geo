<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests;

use Aporat\Geo\Facades\Geo;
use Aporat\Geo\GeoManager;
use Aporat\Geo\Location;
use Aporat\Geo\Testing\GeoFake;
use Illuminate\Http\Request;
use PHPUnit\Framework\AssertionFailedError;

final class GeoFakeTest extends TestCase
{
    public function test_fake_replaces_the_manager(): void
    {
        $fake = Geo::fake();

        self::assertInstanceOf(GeoFake::class, $this->app->make('geo'));
        self::assertSame($fake, $this->app->make(GeoManager::class));
        self::assertSame($fake, Geo::getFacadeRoot());
        self::assertCount(1, $fake->drivers());
    }

    public function test_answers_from_the_map_reserved_addresses_included(): void
    {
        $fake = Geo::fake([
            '127.0.0.1' => new Location(ip: '127.0.0.1', countryCode: 'US', city: 'Austin', timeZone: 'America/Chicago'),
            '10.*' => ['country_code' => 'SE'],
        ]);

        self::assertSame('Austin', Geo::locate('127.0.0.1')?->city);
        self::assertSame('SE', Geo::locate('10.9.9.9')?->countryCode);
        self::assertSame('SE', Geo::country('10.9.9.9'));
        self::assertNull(Geo::locate('8.8.8.8'));
        self::assertNull(Geo::locate('nope'));

        self::assertSame(['127.0.0.1', '10.9.9.9', '10.9.9.9', '8.8.8.8'], $fake->lookups());
        $fake->assertLocated('127.0.0.1')->assertLocated('::ffff:8.8.8.8')->assertNotLocated('1.1.1.1');
    }

    public function test_the_request_macro_goes_through_the_fake(): void
    {
        Geo::fake(['*' => ['country_code' => 'DE']]);
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $this->app->instance('request', $request);

        self::assertSame('DE', $request->location()?->countryCode);
        self::assertSame('DE', Geo::locate()?->countryCode);
    }

    public function test_the_map_can_be_replaced(): void
    {
        $fake = Geo::fake(['*' => ['country_code' => 'DE']]);
        $fake->locations(['*' => ['country_code' => 'FR']]);

        self::assertSame('FR', Geo::locate('8.8.8.8')?->countryCode);
    }

    public function test_assert_nothing_located(): void
    {
        $fake = Geo::fake();
        $fake->assertNothingLocated();

        Geo::locate('8.8.8.8');

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingLocated();
    }

    public function test_assert_located_fails_when_the_address_was_not_asked_for(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No lookup was made for 8.8.8.8');

        Geo::fake()->assertLocated('8.8.8.8');
    }
}
