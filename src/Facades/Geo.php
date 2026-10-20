<?php

declare(strict_types=1);

namespace Aporat\Geo\Facades;

use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\GeoManager;
use Aporat\Geo\Location;
use Aporat\Geo\Testing\GeoFake;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * @method static Location|null locate(?string $ip = null, ?Request $request = null)
 * @method static string|null country(?string $ip = null, ?Request $request = null)
 * @method static list<Driver> drivers()
 * @method static Driver driver(string $name)
 * @method static GeoManager extend(string $name, \Closure $callback)
 * @method static void flush()
 *
 * @see GeoManager
 *
 * @mixin GeoManager
 */
final class Geo extends Facade
{
    /**
     * Replace the manager with one that answers from the given map of
     * address patterns, reserved addresses included, and records every
     * lookup for its assertions.
     *
     * @param  array<string, Location|array<string, mixed>|null>  $locations
     */
    public static function fake(array $locations = []): GeoFake
    {
        $app = self::getFacadeApplication();

        if ($app === null) {
            throw new RuntimeException('The Geo facade has no application to fake.');
        }

        $fake = new GeoFake($app, $locations);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'geo';
    }
}
