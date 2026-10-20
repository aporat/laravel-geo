<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests;

use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\Drivers\ArrayDriver;
use Aporat\Geo\Drivers\CachingDriver;
use Aporat\Geo\Drivers\HeaderDriver;
use Aporat\Geo\Drivers\IpInfoDriver;
use Aporat\Geo\Drivers\MaxMindDriver;
use Aporat\Geo\Exceptions\LookupException;
use Aporat\Geo\Facades\Geo;
use Aporat\Geo\GeoManager;
use Aporat\Geo\Location;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Mockery;

final class GeoManagerTest extends TestCase
{
    public function test_locates_through_the_maxmind_driver_by_default(): void
    {
        $location = Geo::locate('2.125.160.216');

        self::assertSame('Boxford', $location?->city);
        self::assertSame('GB', Geo::country('2.125.160.216'));
        self::assertNull(Geo::locate('1.1.1.1'));
        self::assertNull(Geo::country('1.1.1.1'));
    }

    public function test_reserved_and_invalid_addresses_are_never_looked_up(): void
    {
        $throwing = Mockery::mock(Driver::class);
        $throwing->shouldNotReceive('lookup');
        Geo::extend('throwing', static fn (): Driver => $throwing);
        config(['geo.drivers' => ['throwing']]);

        self::assertNull(Geo::locate('127.0.0.1'));
        self::assertNull(Geo::locate('10.1.2.3'));
        self::assertNull(Geo::locate('fd00::1'));
        self::assertNull(Geo::locate('not-an-ip'));
        self::assertNull(Geo::locate(''));
        self::assertNull(Geo::country('192.168.1.1'));
    }

    public function test_falls_through_the_chain_on_misses_and_failures(): void
    {
        $this->app->instance(ExceptionHandler::class, $handler = Mockery::mock(ExceptionHandler::class));
        $handler->shouldReceive('report')->once()->with(Mockery::type(LookupException::class));

        Geo::extend('broken', static fn (): Driver => new class implements Driver
        {
            public function name(): string
            {
                return 'broken';
            }

            public function lookup(string $ip, ?Request $request = null): ?Location
            {
                throw new LookupException('down');
            }
        });
        Geo::extend('empty', static fn (): Driver => new ArrayDriver(['*' => null]));
        Geo::extend('last', static fn (Container $app, array $config): Driver => new ArrayDriver(['*' => ['country_code' => $config['country']]]));
        config(['geo.drivers' => ['broken', 'empty', 'last'], 'geo.last' => ['country' => 'SE']]);

        self::assertSame('SE', Geo::locate('8.8.8.8')?->countryCode);
    }

    public function test_the_first_answer_wins(): void
    {
        config(['geo.drivers' => ['array', 'maxmind'], 'geo.array' => ['locations' => ['2.125.*' => ['country_code' => 'FR']]]]);

        self::assertSame('FR', Geo::locate('2.125.160.216')?->countryCode);
        self::assertSame('GB', Geo::locate('81.2.69.160')?->countryCode, 'the array driver has nothing, maxmind answers');
    }

    public function test_country_prefers_the_cheap_path_and_skips_drivers_without_one(): void
    {
        config(['geo.drivers' => ['array', 'maxmind'], 'geo.array' => ['locations' => ['2.125.*' => ['country_code' => 'FR']]]]);

        self::assertSame('FR', Geo::country('2.125.160.216'));
        self::assertSame('GB', Geo::country('81.2.69.160'));
    }

    public function test_answers_are_memoised_per_address(): void
    {
        $driver = Mockery::mock(Driver::class);
        $driver->shouldReceive('name')->andReturn('once');
        $driver->shouldReceive('lookup')->once()->with('8.8.8.8', Mockery::any())->andReturn(new Location(ip: '8.8.8.8', countryCode: 'US'));
        $driver->shouldReceive('lookup')->once()->with('1.1.1.1', Mockery::any())->andReturn(null);
        Geo::extend('once', static fn (): Driver => $driver);
        config(['geo.drivers' => ['once']]);

        self::assertSame('US', Geo::locate('8.8.8.8')?->countryCode);
        self::assertSame('US', Geo::locate('::ffff:8.8.8.8')?->countryCode);
        self::assertSame('US', Geo::country('8.8.8.8'));
        self::assertNull(Geo::locate('1.1.1.1'));
        self::assertNull(Geo::locate('1.1.1.1'));

        Geo::flush();
        $driver->shouldReceive('lookup')->once()->with('8.8.8.8', Mockery::any())->andReturn(null);
        self::assertNull(Geo::locate('8.8.8.8'));
    }

    public function test_a_spent_time_budget_skips_the_rest_of_the_chain(): void
    {
        Geo::extend('slow', static fn (): Driver => new class implements Driver
        {
            public function name(): string
            {
                return 'slow';
            }

            public function lookup(string $ip, ?Request $request = null): ?Location
            {
                usleep(20_000);

                return null;
            }
        });
        config(['geo.drivers' => ['slow', 'maxmind'], 'geo.total_timeout' => 0.01]);

        self::assertNull(Geo::locate('2.125.160.216'), 'maxmind would have answered, but the budget was spent');
    }

    public function test_locates_the_current_request_by_default(): void
    {
        $this->app->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '2.125.160.216']));

        self::assertSame('Boxford', Geo::locate()?->city);
        self::assertSame('GB', Geo::country());
        self::assertSame('Boxford', $this->app->make('request')->location()?->city);
    }

    public function test_builds_the_configured_drivers(): void
    {
        config(['geo.ipinfo.cache.ttl' => 60]);

        self::assertInstanceOf(MaxMindDriver::class, Geo::driver('maxmind'));
        self::assertInstanceOf(HeaderDriver::class, Geo::driver('header'));
        self::assertInstanceOf(ArrayDriver::class, Geo::driver('array'));
        $ipinfo = Geo::driver('ipinfo');
        self::assertInstanceOf(CachingDriver::class, $ipinfo);
        self::assertInstanceOf(IpInfoDriver::class, $ipinfo->inner());
        self::assertSame(Geo::driver('maxmind'), Geo::driver('maxmind'));

        config(['geo.ipinfo.cache.ttl' => 0]);
        Geo::flush();
        self::assertInstanceOf(IpInfoDriver::class, Geo::driver('ipinfo'));
    }

    public function test_an_unknown_driver_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Geo driver [nope] is not configured.');

        Geo::driver('nope');
    }

    public function test_failures_can_go_unreported(): void
    {
        $this->app->instance(ExceptionHandler::class, $handler = Mockery::mock(ExceptionHandler::class));
        $handler->shouldNotReceive('report');
        config(['geo.report_failures' => false, 'geo.drivers' => ['broken']]);
        Geo::extend('broken', static fn (): Driver => new class implements Driver
        {
            public function name(): string
            {
                return 'broken';
            }

            public function lookup(string $ip, ?Request $request = null): ?Location
            {
                throw new LookupException('down');
            }
        });

        self::assertNull(Geo::locate('8.8.8.8'));
        self::assertInstanceOf(GeoManager::class, Geo::getFacadeRoot());
    }
}
