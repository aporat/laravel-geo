<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests;

use Aporat\Geo\Location;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LocationTest extends TestCase
{
    public function test_round_trips_through_its_array_form(): void
    {
        $location = new Location(
            ip: '81.2.69.160',
            countryCode: 'GB',
            countryName: 'United Kingdom',
            continentCode: 'EU',
            subdivisionCode: 'ENG',
            subdivisionName: 'England',
            city: 'London',
            latitude: 51.5142,
            longitude: -0.0931,
            accuracyRadius: 100,
            timeZone: 'Europe/London',
            asn: 1221,
            asOrganization: 'Telstra Pty Ltd',
            source: 'maxmind',
        );

        $copy = Location::make($location->toArray());

        self::assertEquals($location, $copy);
        self::assertSame($location->toArray(), $copy->jsonSerialize());
        self::assertSame('GB', $copy->countryCode);
        self::assertSame(1221, $copy->asn);
        self::assertFalse($copy->isInEu);
        self::assertTrue($copy->hasCoordinates());
    }

    public function test_make_coerces_and_ignores_unknown_keys(): void
    {
        $location = Location::make([
            'ip' => '1.2.3.4',
            'country_code' => 'de',
            'is_in_eu' => '1',
            'latitude' => '52.5',
            'asn' => '3320',
            'city' => '',
            'something_new' => 'ignored',
        ]);

        self::assertSame('DE', $location->countryCode);
        self::assertTrue($location->isInEu);
        self::assertSame(52.5, $location->latitude);
        self::assertSame(3320, $location->asn);
        self::assertNull($location->city);
        self::assertNull($location->longitude);
        self::assertFalse($location->hasCoordinates());
    }

    public function test_empty_means_no_country_and_no_network(): void
    {
        self::assertTrue((new Location(ip: '1.2.3.4'))->isEmpty());
        self::assertTrue((new Location(ip: '1.2.3.4', city: 'Nowhere'))->isEmpty());
        self::assertFalse((new Location(ip: '1.2.3.4', countryCode: 'US'))->isEmpty());
        self::assertFalse((new Location(ip: '1.2.3.4', asn: 15169))->isEmpty());
    }

    public function test_merge_fills_what_is_missing_and_keeps_its_own_address(): void
    {
        $place = new Location(ip: '1.2.3.4', countryCode: 'US', city: 'Milton', source: 'maxmind');
        $network = new Location(ip: '9.9.9.9', countryCode: 'CA', asn: 237, asOrganization: 'Merit', source: 'other');

        $merged = $place->merge($network);

        self::assertSame('1.2.3.4', $merged->ip);
        self::assertSame('US', $merged->countryCode);
        self::assertSame('Milton', $merged->city);
        self::assertSame(237, $merged->asn);
        self::assertSame('Merit', $merged->asOrganization);
        self::assertSame('maxmind', $merged->source);
        self::assertSame($place, $place->merge(null));
    }

    public function test_with_replaces_attributes(): void
    {
        $location = (new Location(ip: '1.2.3.4', countryCode: 'US'))->with(['city' => 'Austin', 'country_code' => 'ca']);

        self::assertSame('Austin', $location->city);
        self::assertSame('CA', $location->countryCode);
    }

    public function test_timezone_offset_follows_daylight_saving(): void
    {
        $location = new Location(ip: '1.2.3.4', timeZone: 'America/New_York');

        self::assertSame(-300, $location->timezoneOffsetMinutes(new DateTimeImmutable('2026-01-15 12:00:00 UTC')));
        self::assertSame(-240, $location->timezoneOffsetMinutes(new DateTimeImmutable('2026-07-15 12:00:00 UTC')));
        self::assertSame(330, (new Location(ip: '1.2.3.4', timeZone: 'Asia/Kolkata'))->timezoneOffsetMinutes());
        self::assertContains((new Location(ip: '1.2.3.4', timeZone: 'Europe/London'))->timezoneOffsetMinutes(), [0, 60]);
    }

    public function test_timezone_offset_is_null_without_a_usable_zone(): void
    {
        self::assertNull((new Location(ip: '1.2.3.4'))->timezoneOffsetMinutes());
        self::assertNull((new Location(ip: '1.2.3.4', timeZone: 'Mars/Olympus'))->timezoneOffsetMinutes());
        self::assertNull((new Location(ip: '1.2.3.4', timeZone: 'Mars/Olympus'))->dateTimeZone());
    }

    public function test_currency_and_country_checks(): void
    {
        $location = new Location(ip: '1.2.3.4', countryCode: 'DE');

        self::assertSame('EUR', $location->currency());
        self::assertSame('USD', (new Location(ip: '1.2.3.4', countryCode: 'US'))->currency());
        self::assertSame('GBP', (new Location(ip: '1.2.3.4', countryCode: 'GB'))->currency());
        self::assertNull((new Location(ip: '1.2.3.4', countryCode: 'QQ'))->currency());
        self::assertNull((new Location(ip: '1.2.3.4'))->currency());

        self::assertTrue($location->countryIs('de'));
        self::assertTrue($location->countryIs('FR', 'DE'));
        self::assertFalse($location->countryIs('FR'));
        self::assertFalse((new Location(ip: '1.2.3.4'))->countryIs('DE'));
    }
}
