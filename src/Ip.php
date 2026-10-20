<?php

declare(strict_types=1);

namespace Aporat\Geo;

/**
 * Address helpers: validation, the one canonical spelling of an address,
 * and the reserved ranges no geolocation database has data for.
 */
final class Ip
{
    /**
     * IANA special-purpose IPv4 ranges: private, loopback, link-local,
     * carrier-grade NAT, documentation, benchmarking, multicast, reserved.
     *
     * @var list<string>
     */
    public const array RESERVED_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * IPv6: unspecified, loopback, unique local, link-local, multicast,
     * documentation, discard-only, and the deprecated site-local block.
     *
     * @var list<string>
     */
    public const array RESERVED_V6 = [
        '::/128',
        '::1/128',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /**
     * The canonical form of an address, or null when it is not one: trimmed,
     * IPv6 lower-cased and compressed, and an IPv4-mapped IPv6 address
     * (`::ffff:1.2.3.4`) reduced to the IPv4 it carries.
     */
    public static function normalize(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        // ::ffff:0:0/96 holds IPv4 addresses; answer with the IPv4 form so
        // one address has one cache key and one database branch.
        if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $mapped = inet_ntop(substr($packed, 12));

            return $mapped === false ? null : $mapped;
        }

        $compressed = inet_ntop($packed);

        return $compressed === false ? null : $compressed;
    }

    public static function isValid(?string $ip): bool
    {
        return self::normalize($ip) !== null;
    }

    /**
     * 4 or 6, null for anything that is not an address.
     */
    public static function version(string $ip): ?int
    {
        $ip = self::normalize($ip);

        return match (true) {
            $ip === null => null,
            str_contains($ip, ':') => 6,
            default => 4,
        };
    }

    /**
     * Whether the address is routable on the public internet, so a
     * geolocation database can know it. False for every reserved range.
     */
    public static function isPublic(string $ip): bool
    {
        $ip = self::normalize($ip);
        if ($ip === null) {
            return false;
        }

        $ranges = str_contains($ip, ':') ? self::RESERVED_V6 : self::RESERVED_V4;
        foreach ($ranges as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the address lies in the CIDR block. Addresses of different
     * families never match.
     */
    public static function inCidr(string $ip, string $cidr): bool
    {
        $ip = self::normalize($ip);
        if ($ip === null) {
            return false;
        }

        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $network = self::normalize($network);
        if ($network === null) {
            return false;
        }

        $ipPacked = inet_pton($ip);
        $networkPacked = inet_pton($network);
        if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) {
            return false;
        }

        $maxBits = strlen($ipPacked) * 8;
        $bits = $bits === null ? $maxBits : (int) $bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($ipPacked[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }
}
