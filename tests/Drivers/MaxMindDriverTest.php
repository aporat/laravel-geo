<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests\Drivers;

use Aporat\Geo\Drivers\MaxMindDriver;
use Aporat\Geo\Exceptions\DatabaseException;
use Aporat\Geo\Tests\TestCase;

final class MaxMindDriverTest extends TestCase
{
    private function driver(?string $path = null, ?array $editions = null): MaxMindDriver
    {
        return new MaxMindDriver(
            path: $path ?? $this->fixtures,
            editions: $editions ?? ['city' => 'GeoIP2-City-Test', 'country' => 'GeoLite2-Country-Test', 'asn' => 'GeoLite2-ASN-Test'],
            reopenCheckSeconds: 0,
        );
    }

    public function test_reads_the_city_record(): void
    {
        $location = $this->driver()->lookup('2.125.160.216');

        self::assertNotNull($location);
        self::assertSame('2.125.160.216', $location->ip);
        self::assertSame('GB', $location->countryCode);
        self::assertSame('United Kingdom', $location->countryName);
        self::assertSame('EU', $location->continentCode);
        self::assertFalse($location->isInEu);
        self::assertSame('WBK', $location->subdivisionCode);
        self::assertSame('West Berkshire', $location->subdivisionName);
        self::assertSame('Boxford', $location->city);
        self::assertSame('OX1', $location->postalCode);
        self::assertSame(51.75, $location->latitude);
        self::assertSame(-1.25, $location->longitude);
        self::assertSame(100, $location->accuracyRadius);
        self::assertSame('Europe/London', $location->timeZone);
        self::assertSame('maxmind', $location->source);
        self::assertNull($location->asn, 'the ASN test database has no entry for this block');
    }

    public function test_marks_eu_members(): void
    {
        self::assertTrue($this->driver()->lookup('89.160.20.128')?->isInEu);
    }

    public function test_merges_the_network_record(): void
    {
        $location = $this->driver()->lookup('1.128.0.0');

        self::assertNotNull($location);
        self::assertNull($location->countryCode, 'the city test database has no entry for this block');
        self::assertFalse($location->isEmpty());
        self::assertSame(1221, $location->asn);
        self::assertSame('Telstra Pty Ltd', $location->asOrganization);
    }

    public function test_answers_nothing_for_an_unknown_address(): void
    {
        self::assertNull($this->driver()->lookup('1.1.1.1'));
    }

    public function test_country_uses_the_country_database_when_configured(): void
    {
        $driver = $this->driver();

        self::assertSame('GB', $driver->country('81.2.69.160'));
        self::assertNull($driver->country('1.1.1.1'));
    }

    public function test_country_falls_back_to_the_city_database(): void
    {
        $driver = $this->driver(editions: ['city' => 'GeoIP2-City-Test', 'country' => null, 'asn' => null]);

        self::assertSame('GB', $driver->country('81.2.69.160'));
        self::assertNull($driver->country('1.1.1.1'));
        self::assertNull($driver->lookup('1.128.0.0'), 'no ASN database, no network record');
    }

    public function test_falls_back_to_the_country_database_for_the_place(): void
    {
        $driver = $this->driver(editions: ['city' => null, 'country' => 'GeoLite2-Country-Test', 'asn' => 'GeoLite2-ASN-Test']);

        $location = $driver->lookup('2.125.160.216');

        self::assertSame('GB', $location?->countryCode);
        self::assertSame('EU', $location->continentCode);
        self::assertNull($location->city);
    }

    public function test_missing_files_answer_nothing(): void
    {
        $driver = $this->driver(path: $this->temporaryDirectory());

        self::assertNull($driver->lookup('2.125.160.216'));
        self::assertNull($driver->country('2.125.160.216'));
        self::assertNull($driver->metadata(MaxMindDriver::ROLE_CITY));
    }

    public function test_a_corrupt_file_is_a_database_exception(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory.'/GeoIP2-City-Test.mmdb', 'not a database');
        $driver = $this->driver(path: $directory);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('MaxMind city database');

        $driver->lookup('2.125.160.216');
    }

    public function test_reopens_when_the_file_on_disk_changes(): void
    {
        $directory = $this->temporaryDirectory();
        $driver = $this->driver(path: $directory, editions: ['city' => 'GeoIP2-City-Test']);

        self::assertNull($driver->lookup('2.125.160.216'), 'nothing on disk yet');

        copy($this->fixtures.'/GeoIP2-City-Test.mmdb', $directory.'/GeoIP2-City-Test.mmdb');
        self::assertSame('Boxford', $driver->lookup('2.125.160.216')?->city, 'picked up the new file');

        unlink($directory.'/GeoIP2-City-Test.mmdb');
        self::assertNull($driver->lookup('2.125.160.216'), 'noticed the file went away');

        // A swapped file with an older mtime is still a different file.
        copy($this->fixtures.'/GeoIP2-City-Test.mmdb', $directory.'/GeoIP2-City-Test.mmdb');
        touch($directory.'/GeoIP2-City-Test.mmdb', time() - 3600);
        self::assertSame('Boxford', $driver->lookup('2.125.160.216')?->city);

        $driver->reset();
        self::assertSame('Boxford', $driver->lookup('2.125.160.216')?->city);
    }

    public function test_describes_its_editions_and_paths(): void
    {
        $driver = $this->driver(path: '/data/geo/');

        self::assertSame('/data/geo', $driver->directory());
        self::assertSame(['GeoIP2-City-Test', 'GeoLite2-Country-Test', 'GeoLite2-ASN-Test'], $driver->editions());
        self::assertSame('/data/geo/GeoLite2-ASN-Test.mmdb', $driver->databasePath(MaxMindDriver::ROLE_ASN));
        self::assertNull($this->driver(editions: ['city' => 'X', 'country' => ''])->databasePath(MaxMindDriver::ROLE_COUNTRY));
        self::assertSame('maxmind', $driver->name());
        self::assertSame('GeoIP2-City', $this->driver()->metadata(MaxMindDriver::ROLE_CITY)?->databaseType);
    }
}
