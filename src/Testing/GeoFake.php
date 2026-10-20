<?php

declare(strict_types=1);

namespace Aporat\Geo\Testing;

use Aporat\Geo\Drivers\ArrayDriver;
use Aporat\Geo\GeoManager;
use Aporat\Geo\Ip;
use Aporat\Geo\Location;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * The manager answering from a fixed map instead of the chain. Unlike the
 * real manager it answers for reserved addresses too, so a test client on
 * 127.0.0.1 can be placed anywhere.
 */
class GeoFake extends GeoManager
{
    protected ArrayDriver $fakeDriver;

    /** @var list<string> */
    protected array $lookups = [];

    /**
     * @param  array<string, Location|array<string, mixed>|null>  $locations
     */
    public function __construct(Container $app, array $locations = [])
    {
        parent::__construct($app);

        $this->fakeDriver = new ArrayDriver($locations);
    }

    /**
     * Replace the map.
     *
     * @param  array<string, Location|array<string, mixed>|null>  $locations
     */
    public function locations(array $locations): static
    {
        $this->fakeDriver = new ArrayDriver($locations);

        return $this;
    }

    public function locate(?string $ip = null, ?Request $request = null): ?Location
    {
        $request ??= $this->currentRequest();
        $ip = Ip::normalize($ip ?? $request?->ip());
        if ($ip === null) {
            return null;
        }

        $this->lookups[] = $ip;

        return $this->fakeDriver->lookup($ip, $request);
    }

    public function country(?string $ip = null, ?Request $request = null): ?string
    {
        return $this->locate($ip, $request)?->countryCode;
    }

    public function drivers(): array
    {
        return [$this->fakeDriver];
    }

    /**
     * Every address looked up, in order.
     *
     * @return list<string>
     */
    public function lookups(): array
    {
        return $this->lookups;
    }

    public function assertLocated(string $ip): static
    {
        PHPUnit::assertContains(Ip::normalize($ip), $this->lookups, sprintf('No lookup was made for %s.', $ip));

        return $this;
    }

    public function assertNotLocated(string $ip): static
    {
        PHPUnit::assertNotContains(Ip::normalize($ip), $this->lookups, sprintf('An unexpected lookup was made for %s.', $ip));

        return $this;
    }

    public function assertNothingLocated(): static
    {
        PHPUnit::assertEmpty($this->lookups, sprintf('%d unexpected lookup(s) were made.', count($this->lookups)));

        return $this;
    }
}
