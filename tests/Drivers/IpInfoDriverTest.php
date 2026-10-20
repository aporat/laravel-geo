<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests\Drivers;

use Aporat\Geo\Drivers\CachingDriver;
use Aporat\Geo\Drivers\IpInfoDriver;
use Aporat\Geo\Exceptions\LookupException;
use Aporat\Geo\Location;
use Aporat\Geo\Tests\TestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class IpInfoDriverTest extends TestCase
{
    private function driver(?string $token = 'secret'): IpInfoDriver
    {
        return new IpInfoDriver($this->app->make(HttpFactory::class), $token);
    }

    public function test_maps_the_answer(): void
    {
        Http::fake(['ipinfo.io/*' => Http::response([
            'ip' => '8.8.8.8',
            'city' => 'Mountain View',
            'region' => 'California',
            'country' => 'us',
            'loc' => '37.4056,-122.0775',
            'org' => 'AS15169 Google LLC',
            'postal' => '94043',
            'timezone' => 'America/Los_Angeles',
            'anycast' => true,
        ])]);

        $location = $this->driver()->lookup('8.8.8.8');

        self::assertNotNull($location);
        self::assertSame('US', $location->countryCode);
        self::assertSame('California', $location->subdivisionName);
        self::assertSame('Mountain View', $location->city);
        self::assertSame('94043', $location->postalCode);
        self::assertSame(37.4056, $location->latitude);
        self::assertSame(-122.0775, $location->longitude);
        self::assertSame('America/Los_Angeles', $location->timeZone);
        self::assertSame(15169, $location->asn);
        self::assertSame('Google LLC', $location->asOrganization);
        self::assertTrue($location->isAnycast);
        self::assertSame('ipinfo', $location->source);

        Http::assertSent(static fn (ClientRequest $request): bool => $request->url() === 'https://ipinfo.io/8.8.8.8/json'
            && $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_sends_no_token_when_none_is_configured(): void
    {
        Http::fake(['ipinfo.io/*' => Http::response(['country' => 'DE', 'org' => 'Deutsche Telekom'])]);

        $location = $this->driver(null)->lookup('1.2.3.4');

        self::assertSame('DE', $location?->countryCode);
        self::assertNull($location->asn);
        self::assertSame('Deutsche Telekom', $location->asOrganization);
        Http::assertSent(static fn (ClientRequest $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function test_bogons_and_answers_without_a_country_are_misses(): void
    {
        Http::fake([
            'ipinfo.io/10.0.0.1/json' => Http::response(['ip' => '10.0.0.1', 'bogon' => true]),
            'ipinfo.io/1.2.3.4/json' => Http::response(['ip' => '1.2.3.4']),
        ]);

        self::assertNull($this->driver()->lookup('10.0.0.1'));
        self::assertNull($this->driver()->lookup('1.2.3.4'));
    }

    public function test_a_refusal_is_a_lookup_exception(): void
    {
        Http::fake(['ipinfo.io/*' => Http::response(['error' => 'rate limited'], 429)]);

        $this->expectException(LookupException::class);
        $this->expectExceptionMessage('HTTP 429');

        $this->driver()->lookup('8.8.8.8');
    }

    public function test_a_transport_failure_is_a_lookup_exception(): void
    {
        Http::fake(static fn () => throw new \RuntimeException('connection refused'));

        $this->expectException(LookupException::class);
        $this->expectExceptionMessage('connection refused');

        $this->driver()->lookup('8.8.8.8');
    }

    public function test_the_caching_decorator_remembers_answers_and_misses(): void
    {
        Http::fake([
            'ipinfo.io/8.8.8.8/json' => Http::response(['country' => 'US']),
            'ipinfo.io/1.2.3.4/json' => Http::response(['ip' => '1.2.3.4']),
        ]);
        $driver = new CachingDriver($this->driver(), Cache::store('array'), 3600, 'test');

        self::assertSame('US', $driver->lookup('8.8.8.8')?->countryCode);
        self::assertSame('US', $driver->lookup('8.8.8.8')?->countryCode);
        self::assertSame('US', $driver->country('8.8.8.8'));
        self::assertNull($driver->lookup('1.2.3.4'));
        self::assertNull($driver->lookup('1.2.3.4'));
        Http::assertSentCount(2);

        self::assertInstanceOf(Location::class, Location::make((array) Cache::store('array')->get('test:ipinfo:8.8.8.8')));
        self::assertSame('ipinfo', $driver->name());
        self::assertInstanceOf(IpInfoDriver::class, $driver->inner());

        $driver->forget('8.8.8.8');
        $driver->lookup('8.8.8.8');
        Http::assertSentCount(3);
    }

    public function test_the_caching_decorator_does_not_remember_failures(): void
    {
        Http::fakeSequence('ipinfo.io/*')->push(['error' => 'down'], 503)->push(['country' => 'US']);
        $driver = new CachingDriver($this->driver(), Cache::store('array'), 3600);

        try {
            $driver->lookup('8.8.8.8');
            self::fail('expected a LookupException');
        } catch (LookupException) {
        }

        self::assertSame('US', $driver->lookup('8.8.8.8')?->countryCode);
    }
}
