<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests\Drivers;

use Aporat\Geo\Drivers\ArrayDriver;
use Aporat\Geo\Location;
use PHPUnit\Framework\TestCase;

final class ArrayDriverTest extends TestCase
{
    public function test_matches_exact_addresses_and_wildcards_in_order(): void
    {
        $driver = new ArrayDriver([
            '10.1.2.3' => new Location(ip: '10.1.2.3', countryCode: 'US', city: 'Austin'),
            '10.1.*' => ['country_code' => 'CA'],
            '2001:db8:*' => ['country_code' => 'SE'],
            '*' => null,
        ]);

        self::assertSame('Austin', $driver->lookup('10.1.2.3')?->city);
        self::assertSame('CA', $driver->lookup('10.1.9.9')?->countryCode);
        self::assertSame('10.1.9.9', $driver->lookup('10.1.9.9')?->ip);
        self::assertSame('SE', $driver->lookup('2001:db8::1')?->countryCode);
        self::assertNull($driver->lookup('8.8.8.8'));
        self::assertSame('array', $driver->name());
    }

    public function test_answers_nothing_without_a_match(): void
    {
        self::assertNull((new ArrayDriver)->lookup('8.8.8.8'));
        self::assertNull((new ArrayDriver(['1.1.1.1' => ['country_code' => 'AU']]))->lookup('8.8.8.8'));
    }

    public function test_a_location_for_another_address_is_re_addressed(): void
    {
        $driver = new ArrayDriver(['*' => new Location(ip: '9.9.9.9', countryCode: 'DE')]);

        $location = $driver->lookup('1.1.1.1');

        self::assertSame('1.1.1.1', $location?->ip);
        self::assertSame('DE', $location->countryCode);
    }

    public function test_pattern_matching(): void
    {
        self::assertTrue(ArrayDriver::matches('*', 'anything'));
        self::assertTrue(ArrayDriver::matches('1.2.*.*', '1.2.3.4'));
        self::assertFalse(ArrayDriver::matches('1.2.*.*', '1.20.3.4'));
        self::assertTrue(ArrayDriver::matches('1.2.*', '1.2.3.4'));
        self::assertFalse(ArrayDriver::matches('1.2.3.4', '1.2.3.40'));
    }
}
