<?php

declare(strict_types=1);

namespace Aporat\Geo\Drivers;

use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\Location;
use Illuminate\Http\Request;

/**
 * Answers from a fixed map of address patterns, for tests and for pinning
 * known addresses (an office, a monitor). `*` matches any run of
 * characters, so `10.1.*` covers a block and `*` everything.
 */
class ArrayDriver implements Driver
{
    /** @var array<string, Location|array<string, mixed>|null> */
    protected array $locations;

    /**
     * @param  array<string, Location|array<string, mixed>|null>  $locations  pattern => Location, its array form, or null for "no data"
     */
    public function __construct(array $locations = [])
    {
        $this->locations = $locations;
    }

    public function name(): string
    {
        return 'array';
    }

    public function lookup(string $ip, ?Request $request = null): ?Location
    {
        foreach ($this->locations as $pattern => $location) {
            if (! self::matches((string) $pattern, $ip)) {
                continue;
            }

            if ($location === null) {
                return null;
            }

            $location = $location instanceof Location ? $location : Location::make($location + ['ip' => $ip]);

            return $location->ip === $ip ? $location : $location->with(['ip' => $ip]);
        }

        return null;
    }

    public static function matches(string $pattern, string $ip): bool
    {
        if ($pattern === '*' || $pattern === $ip) {
            return true;
        }

        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';

        return preg_match($regex, $ip) === 1;
    }
}
