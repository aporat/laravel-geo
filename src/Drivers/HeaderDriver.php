<?php

declare(strict_types=1);

namespace Aporat\Geo\Drivers;

use Aporat\Geo\Contracts\CountryLookup;
use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\Ip;
use Aporat\Geo\Location;
use Illuminate\Http\Request;

/**
 * Reads the location a CDN stamped on the request: CloudFront's
 * `CloudFront-Viewer-*` headers, Cloudflare's `CF-IPCountry` and friends.
 * Costs nothing, but is only as trustworthy as the edge in front of the
 * app, and only describes the request's own client, so a lookup for any
 * other address answers nothing.
 */
class HeaderDriver implements CountryLookup, Driver
{
    /** Values the edges use for "unknown" (CloudFront, Cloudflare) and Tor (Cloudflare). */
    protected const array UNKNOWN_COUNTRIES = ['', 'XX', 'T1', 'ZZ'];

    /**
     * @param  array<string, list<string>>  $headers  field => header names to try, in order
     */
    public function __construct(protected readonly array $headers) {}

    public function name(): string
    {
        return 'header';
    }

    public function lookup(string $ip, ?Request $request = null): ?Location
    {
        if (! $this->describes($ip, $request)) {
            return null;
        }

        $country = $this->country($ip, $request);
        if ($country === null) {
            return null;
        }

        $asn = $this->header($request, 'asn');

        return new Location(
            ip: $ip,
            countryCode: $country,
            subdivisionCode: $this->header($request, 'subdivision'),
            subdivisionName: $this->header($request, 'subdivision_name'),
            city: $this->header($request, 'city'),
            postalCode: $this->header($request, 'postal_code'),
            latitude: $this->float($this->header($request, 'latitude')),
            longitude: $this->float($this->header($request, 'longitude')),
            timeZone: $this->header($request, 'time_zone'),
            asn: $asn !== null && is_numeric($asn) ? (int) $asn : null,
            source: $this->name(),
        );
    }

    public function country(string $ip, ?Request $request = null): ?string
    {
        if (! $this->describes($ip, $request)) {
            return null;
        }

        $country = strtoupper($this->header($request, 'country') ?? '');

        return in_array($country, self::UNKNOWN_COUNTRIES, true) || strlen($country) !== 2 ? null : $country;
    }

    /**
     * @phpstan-assert-if-true Request $request
     */
    protected function describes(string $ip, ?Request $request): bool
    {
        return $request !== null && Ip::normalize($request->ip()) === $ip;
    }

    protected function header(Request $request, string $field): ?string
    {
        foreach ($this->headers[$field] ?? [] as $name) {
            $value = $request->headers->get($name);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    protected function float(?string $value): ?float
    {
        return $value !== null && is_numeric($value) ? (float) $value : null;
    }
}
