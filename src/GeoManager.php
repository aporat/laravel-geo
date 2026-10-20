<?php

declare(strict_types=1);

namespace Aporat\Geo;

use Aporat\Geo\Contracts\CountryLookup;
use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\Drivers\ArrayDriver;
use Aporat\Geo\Drivers\CachingDriver;
use Aporat\Geo\Drivers\HeaderDriver;
use Aporat\Geo\Drivers\IpInfoDriver;
use Aporat\Geo\Drivers\MaxMindDriver;
use Aporat\Geo\Support\Config;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Runs the configured driver chain: the first driver with an answer wins,
 * one with no data or a failure hands over to the next. Answers are
 * memoised per address for the life of the process, so a request that
 * asks twice, or a worker that sees the same client repeatedly, pays once.
 */
class GeoManager
{
    protected const int MEMO_LIMIT = 512;

    /** @var array<string, Driver> */
    protected array $drivers = [];

    /** @var array<string, Closure(Container, array<string, mixed>): Driver> */
    protected array $customCreators = [];

    /** @var array<string, Location|null> */
    protected array $memo = [];

    public function __construct(protected readonly Container $app) {}

    /**
     * The location of an address, or of the current request's client when
     * none is given. Null for an address no driver knows, and for every
     * reserved address (private, loopback, link-local), which are never
     * looked up.
     */
    public function locate(?string $ip = null, ?Request $request = null): ?Location
    {
        $request ??= $this->currentRequest();
        $ip = Ip::normalize($ip ?? $request?->ip());
        if ($ip === null || ! Ip::isPublic($ip)) {
            return null;
        }

        if (array_key_exists($ip, $this->memo)) {
            return $this->memo[$ip];
        }

        $location = null;
        $deadline = microtime(true) + $this->totalTimeout();
        foreach ($this->drivers() as $driver) {
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                $answer = $driver->lookup($ip, $request);
            } catch (Throwable $e) {
                $this->reportFailure($driver, $e);

                continue;
            }

            if ($answer !== null && ! $answer->isEmpty()) {
                $location = $answer;
                break;
            }
        }

        return $this->remember($ip, $location);
    }

    /**
     * The ISO 3166-1 alpha-2 country of an address, through the drivers
     * that answer it cheaply where they can.
     */
    public function country(?string $ip = null, ?Request $request = null): ?string
    {
        $request ??= $this->currentRequest();
        $ip = Ip::normalize($ip ?? $request?->ip());
        if ($ip === null || ! Ip::isPublic($ip)) {
            return null;
        }

        if (array_key_exists($ip, $this->memo)) {
            return $this->memo[$ip]?->countryCode;
        }

        $deadline = microtime(true) + $this->totalTimeout();
        foreach ($this->drivers() as $driver) {
            if (microtime(true) > $deadline) {
                break;
            }

            try {
                if ($driver instanceof CountryLookup) {
                    $country = $driver->country($ip, $request);
                    if ($country !== null) {
                        return $country;
                    }

                    continue;
                }

                $answer = $driver->lookup($ip, $request);
            } catch (Throwable $e) {
                $this->reportFailure($driver, $e);

                continue;
            }

            if ($answer?->countryCode !== null) {
                return $this->remember($ip, $answer)?->countryCode;
            }
        }

        return null;
    }

    /**
     * The chain, in the configured order.
     *
     * @return list<Driver>
     */
    public function drivers(): array
    {
        /** @var list<string> $names */
        $names = $this->config('drivers', ['maxmind']);

        return array_map($this->driver(...), $names);
    }

    /**
     * One driver by name, built once from its config block.
     */
    public function driver(string $name): Driver
    {
        return $this->drivers[$name] ??= $this->createDriver($name);
    }

    /**
     * Register a driver under a name, for the chain or for `driver()`.
     *
     * @param  Closure(Container, array<string, mixed>): Driver  $callback
     */
    public function extend(string $name, Closure $callback): static
    {
        $this->customCreators[$name] = $callback;
        unset($this->drivers[$name]);

        return $this;
    }

    /**
     * Drop every memoised answer and built driver.
     */
    public function flush(): void
    {
        $this->memo = [];
        $this->drivers = [];
    }

    protected function createDriver(string $name): Driver
    {
        /** @var array<string, mixed> $config */
        $config = $this->config($name, []);

        if (isset($this->customCreators[$name])) {
            return $this->customCreators[$name]($this->app, $config);
        }

        $driver = match ($name) {
            'maxmind' => $this->createMaxmindDriver($config),
            'header' => $this->createHeaderDriver($config),
            'ipinfo' => $this->createIpinfoDriver($config),
            'array' => $this->createArrayDriver($config),
            default => throw new InvalidArgumentException(sprintf('Geo driver [%s] is not configured.', $name)),
        };

        return $this->wrapInCache($driver, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createMaxmindDriver(array $config): Driver
    {
        /** @var array<string, string|null> $editions */
        $editions = $config['editions'] ?? [];
        /** @var list<string> $locales */
        $locales = $config['locales'] ?? ['en'];

        return new MaxMindDriver(
            path: Config::string($config['path'] ?? null),
            editions: $editions,
            locales: $locales,
            reopenCheckSeconds: Config::float($config['reopen_check_seconds'] ?? null, 60),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createHeaderDriver(array $config): Driver
    {
        /** @var array<string, list<string>> $headers */
        $headers = $config['headers'] ?? [];

        return new HeaderDriver($headers);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createIpinfoDriver(array $config): Driver
    {
        return new IpInfoDriver(
            http: $this->app->make(HttpFactory::class),
            token: Config::nullableString($config['token'] ?? null),
            url: Config::string($config['url'] ?? null, 'https://ipinfo.io/{ip}/json'),
            timeout: Config::float($config['timeout'] ?? null, 2.0),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createArrayDriver(array $config): Driver
    {
        /** @var array<string, Location|array<string, mixed>|null> $locations */
        $locations = $config['locations'] ?? [];

        return new ArrayDriver($locations);
    }

    /**
     * Wrap a driver whose config block carries a `cache.ttl`.
     *
     * @param  array<string, mixed>  $config
     */
    protected function wrapInCache(Driver $driver, array $config): Driver
    {
        $cache = Config::array($config['cache'] ?? null);
        $ttl = Config::int($cache['ttl'] ?? null);
        if ($ttl <= 0) {
            return $driver;
        }

        return new CachingDriver(
            inner: $driver,
            cache: $this->app->make(CacheFactory::class)->store(Config::nullableString($cache['store'] ?? null)),
            ttl: $ttl,
            prefix: Config::string($cache['prefix'] ?? null, 'geo'),
        );
    }

    protected function remember(string $ip, ?Location $location): ?Location
    {
        if (count($this->memo) >= static::MEMO_LIMIT) {
            $this->memo = array_slice($this->memo, intdiv(static::MEMO_LIMIT, 2), null, true);
        }

        return $this->memo[$ip] = $location;
    }

    protected function reportFailure(Driver $driver, Throwable $e): void
    {
        if (! Config::bool($this->config('report_failures', true), true)) {
            return;
        }

        if ($this->app->bound(ExceptionHandler::class)) {
            $this->app->make(ExceptionHandler::class)->report($e);
        }
    }

    protected function currentRequest(): ?Request
    {
        return $this->app->bound('request') ? $this->app->make('request') : null;
    }

    protected function totalTimeout(): float
    {
        return Config::float($this->config('total_timeout', 2.0), 2.0);
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get('geo.'.$key, $default);
    }
}
