<?php

declare(strict_types=1);

namespace Aporat\Geo\Drivers;

use Aporat\Geo\Contracts\CountryLookup;
use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\Location;
use Aporat\Geo\Support\Config;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;

/**
 * Remembers another driver's answers, misses included, for a TTL. Meant
 * for the HTTP drivers: a local database lookup is faster than the cache
 * round trip, so those are never wrapped. Failures are not remembered.
 */
class CachingDriver implements CountryLookup, Driver
{
    /** Stored in place of a null answer, so a known miss is not asked again. */
    protected const array MISS = ['miss' => true];

    public function __construct(
        protected readonly Driver $inner,
        protected readonly Repository $cache,
        protected readonly int $ttl,
        protected readonly string $prefix = 'geo',
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function inner(): Driver
    {
        return $this->inner;
    }

    public function lookup(string $ip, ?Request $request = null): ?Location
    {
        $key = $this->key($ip);

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached === self::MISS ? null : Location::make(Config::array($cached));
        }

        $location = $this->inner->lookup($ip, $request);
        $this->cache->put($key, $location?->toArray() ?? self::MISS, $this->ttl);

        return $location;
    }

    public function country(string $ip, ?Request $request = null): ?string
    {
        if ($this->inner instanceof CountryLookup && $this->cache->get($this->key($ip)) === null) {
            // The inner driver answers the country cheaply; a full lookup
            // would also be cached, so only short-cut when nothing is.
            return $this->inner->country($ip, $request);
        }

        return $this->lookup($ip, $request)?->countryCode;
    }

    public function forget(string $ip): void
    {
        $this->cache->forget($this->key($ip));
    }

    protected function key(string $ip): string
    {
        return sprintf('%s:%s:%s', $this->prefix, $this->inner->name(), $ip);
    }
}
