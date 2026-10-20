<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests;

use Aporat\Geo\Ip;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpTest extends TestCase
{
    public function test_normalizes_spelling(): void
    {
        self::assertSame('1.2.3.4', Ip::normalize(' 1.2.3.4 '));
        self::assertSame('1.2.3.4', Ip::normalize('::ffff:1.2.3.4'));
        self::assertSame('1.2.3.4', Ip::normalize('::FFFF:102:304'));
        self::assertSame('2001:db8::1', Ip::normalize('2001:0DB8:0000:0000:0000:0000:0000:0001'));
        self::assertSame('::1', Ip::normalize('0:0:0:0:0:0:0:1'));
    }

    public function test_rejects_what_is_not_an_address(): void
    {
        self::assertNull(Ip::normalize(null));
        self::assertNull(Ip::normalize(''));
        self::assertNull(Ip::normalize('   '));
        self::assertNull(Ip::normalize('1.2.3'));
        self::assertNull(Ip::normalize('1.2.3.256'));
        self::assertNull(Ip::normalize('example.com'));
        self::assertNull(Ip::normalize('1.2.3.4, 5.6.7.8'));
        self::assertFalse(Ip::isValid('nope'));
        self::assertTrue(Ip::isValid('8.8.8.8'));
        self::assertNull(Ip::version('nope'));
        self::assertSame(4, Ip::version('::ffff:8.8.8.8'));
        self::assertSame(6, Ip::version('2606:4700::1111'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedAddresses(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback block' => ['127.255.0.9'];
        yield 'rfc1918 10/8 beyond 10.0' => ['10.1.2.3'];
        yield 'rfc1918 172.16/12' => ['172.31.255.1'];
        yield 'rfc1918 192.168/16' => ['192.168.1.1'];
        yield 'carrier-grade nat' => ['100.127.1.1'];
        yield 'link-local' => ['169.254.10.10'];
        yield 'documentation' => ['203.0.113.7'];
        yield 'benchmarking' => ['198.19.0.1'];
        yield 'multicast' => ['239.1.1.1'];
        yield 'reserved' => ['240.0.0.1'];
        yield 'broadcast' => ['255.255.255.255'];
        yield 'this network' => ['0.0.0.0'];
        yield 'v6 loopback' => ['::1'];
        yield 'v6 unspecified' => ['::'];
        yield 'v6 unique local' => ['fd12:3456::1'];
        yield 'v6 unique local fc' => ['fc00::1'];
        yield 'v6 link-local' => ['fe80::1'];
        yield 'v6 multicast' => ['ff02::1'];
        yield 'v6 documentation' => ['2001:db8::1'];
        yield 'v6 mapped private' => ['::ffff:192.168.0.1'];
        yield 'not an address' => ['garbage'];
    }

    #[DataProvider('reservedAddresses')]
    public function test_reserved_addresses_are_not_public(string $ip): void
    {
        self::assertFalse(Ip::isPublic($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicAddresses(): iterable
    {
        yield 'google dns' => ['8.8.8.8'];
        yield 'next to 172.16/12' => ['172.32.0.1'];
        yield 'next to cgnat' => ['100.128.0.1'];
        yield 'next to 10/8' => ['11.0.0.1'];
        yield 'mapped public' => ['::ffff:8.8.8.8'];
        yield 'v6 public' => ['2606:4700:4700::1111'];
        yield 'v6 next to unique local' => ['fe00::1'];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_public(string $ip): void
    {
        self::assertTrue(Ip::isPublic($ip));
    }

    public function test_cidr_membership(): void
    {
        self::assertTrue(Ip::inCidr('10.1.2.3', '10.0.0.0/8'));
        self::assertTrue(Ip::inCidr('172.16.0.1', '172.16.0.0/12'));
        self::assertFalse(Ip::inCidr('172.32.0.1', '172.16.0.0/12'));
        self::assertTrue(Ip::inCidr('1.2.3.4', '1.2.3.4'));
        self::assertTrue(Ip::inCidr('1.2.3.4', '1.2.3.4/32'));
        self::assertFalse(Ip::inCidr('1.2.3.5', '1.2.3.4/32'));
        self::assertTrue(Ip::inCidr('1.2.3.5', '0.0.0.0/0'));
        self::assertTrue(Ip::inCidr('fd00::1', 'fc00::/7'));
        self::assertFalse(Ip::inCidr('fe00::1', 'fc00::/7'));
        self::assertFalse(Ip::inCidr('10.0.0.1', 'fc00::/7'), 'families never match');
        self::assertFalse(Ip::inCidr('10.0.0.1', '10.0.0.0/33'));
        self::assertFalse(Ip::inCidr('10.0.0.1', 'nope/8'));
        self::assertFalse(Ip::inCidr('nope', '10.0.0.0/8'));
    }
}
