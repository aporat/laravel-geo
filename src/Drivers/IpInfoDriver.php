<?php

declare(strict_types=1);

namespace Aporat\Geo\Drivers;

use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\Exceptions\LookupException;
use Aporat\Geo\Location;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Throwable;

/**
 * ipinfo.io over HTTP. Wrap it in a CachingDriver (the manager does, from
 * config) so an address costs one request per TTL.
 */
class IpInfoDriver implements Driver
{
    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly ?string $token,
        protected readonly string $url = 'https://ipinfo.io/{ip}/json',
        protected readonly float $timeout = 2.0,
    ) {}

    public function name(): string
    {
        return 'ipinfo';
    }

    public function lookup(string $ip, ?Request $request = null): ?Location
    {
        $url = str_replace('{ip}', rawurlencode($ip), $this->url);

        try {
            $pending = $this->http->acceptJson()->timeout($this->timeout);
            if ($this->token !== null && $this->token !== '') {
                $pending = $pending->withToken($this->token);
            }
            $response = $pending->get($url);
        } catch (Throwable $e) {
            throw new LookupException('ipinfo request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new LookupException(sprintf('ipinfo answered HTTP %d for %s', $response->status(), $ip));
        }

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];

        if (($data['bogon'] ?? false) === true || ! isset($data['country']) || ! is_string($data['country'])) {
            return null;
        }

        [$latitude, $longitude] = $this->coordinates($data['loc'] ?? null);
        [$asn, $organization] = $this->organization($data['org'] ?? null);

        return new Location(
            ip: $ip,
            countryCode: strtoupper($data['country']),
            subdivisionName: $this->string($data['region'] ?? null),
            city: $this->string($data['city'] ?? null),
            postalCode: $this->string($data['postal'] ?? null),
            latitude: $latitude,
            longitude: $longitude,
            timeZone: $this->string($data['timezone'] ?? null),
            asn: $asn,
            asOrganization: $organization,
            isAnycast: ($data['anycast'] ?? false) === true,
            source: $this->name(),
        );
    }

    /**
     * @return array{?float, ?float}
     */
    protected function coordinates(mixed $loc): array
    {
        if (! is_string($loc) || ! str_contains($loc, ',')) {
            return [null, null];
        }

        [$latitude, $longitude] = explode(',', $loc, 2);

        return is_numeric($latitude) && is_numeric($longitude) ? [(float) $latitude, (float) $longitude] : [null, null];
    }

    /**
     * "AS13335 Cloudflare, Inc." into its number and name.
     *
     * @return array{?int, ?string}
     */
    protected function organization(mixed $org): array
    {
        if (! is_string($org) || trim($org) === '') {
            return [null, null];
        }

        if (preg_match('/^AS(\d+)\s*(.*)$/i', trim($org), $matches) === 1) {
            return [(int) $matches[1], $matches[2] !== '' ? $matches[2] : null];
        }

        return [null, trim($org)];
    }

    protected function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
