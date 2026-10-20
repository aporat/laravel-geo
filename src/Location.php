<?php

declare(strict_types=1);

namespace Aporat\Geo;

use Aporat\Geo\Support\Currencies;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Throwable;

/**
 * What a lookup found for one address. Every field but the address is
 * optional: a country-only driver fills `countryCode` alone, the ASN
 * database fills the network fields alone, and the two are merged.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Location implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $ip,
        public ?string $countryCode = null,
        public ?string $countryName = null,
        public ?string $continentCode = null,
        public bool $isInEu = false,
        public ?string $subdivisionCode = null,
        public ?string $subdivisionName = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $accuracyRadius = null,
        public ?string $timeZone = null,
        public ?int $asn = null,
        public ?string $asOrganization = null,
        public bool $isAnycast = false,
        public ?string $source = null,
    ) {}

    /**
     * Build from the snake_case array `toArray()` produces. Unknown keys are
     * ignored, so a cached or serialised copy from an older version loads.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function make(array $attributes): self
    {
        $string = static fn (string $key): ?string => isset($attributes[$key]) && is_scalar($attributes[$key]) && (string) $attributes[$key] !== ''
            ? (string) $attributes[$key]
            : null;
        $float = static fn (string $key): ?float => isset($attributes[$key]) && is_numeric($attributes[$key]) ? (float) $attributes[$key] : null;
        $int = static fn (string $key): ?int => isset($attributes[$key]) && is_numeric($attributes[$key]) ? (int) $attributes[$key] : null;
        $bool = static fn (string $key): bool => filter_var($attributes[$key] ?? false, FILTER_VALIDATE_BOOLEAN);

        return new self(
            ip: $string('ip') ?? '',
            countryCode: self::upper($string('country_code')),
            countryName: $string('country_name'),
            continentCode: self::upper($string('continent_code')),
            isInEu: $bool('is_in_eu'),
            subdivisionCode: $string('subdivision_code'),
            subdivisionName: $string('subdivision_name'),
            city: $string('city'),
            postalCode: $string('postal_code'),
            latitude: $float('latitude'),
            longitude: $float('longitude'),
            accuracyRadius: $int('accuracy_radius'),
            timeZone: $string('time_zone'),
            asn: $int('asn'),
            asOrganization: $string('as_organization'),
            isAnycast: $bool('is_anycast'),
            source: $string('source'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName,
            'continent_code' => $this->continentCode,
            'is_in_eu' => $this->isInEu,
            'subdivision_code' => $this->subdivisionCode,
            'subdivision_name' => $this->subdivisionName,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy_radius' => $this->accuracyRadius,
            'time_zone' => $this->timeZone,
            'asn' => $this->asn,
            'as_organization' => $this->asOrganization,
            'is_anycast' => $this->isAnycast,
            'source' => $this->source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * A copy with these snake_case attributes replaced.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function with(array $attributes): self
    {
        return self::make(array_replace($this->toArray(), $attributes));
    }

    /**
     * A copy where every field this one lacks is taken from the other.
     */
    public function merge(?self $other): self
    {
        if ($other === null) {
            return $this;
        }

        $merged = $this->toArray();
        foreach ($other->toArray() as $key => $value) {
            if ($merged[$key] === null || $merged[$key] === false || $merged[$key] === '') {
                $merged[$key] = $value;
            }
        }
        $merged['ip'] = $this->ip;

        return self::make($merged);
    }

    /**
     * Nothing was found: no country and no network.
     */
    public function isEmpty(): bool
    {
        return $this->countryCode === null && $this->asn === null;
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Whether the country is one of the given ISO 3166-1 alpha-2 codes.
     */
    public function countryIs(string ...$codes): bool
    {
        if ($this->countryCode === null) {
            return false;
        }

        return in_array($this->countryCode, array_map(strtoupper(...), $codes), true);
    }

    /**
     * The zone's offset from UTC in minutes at the given instant, now by
     * default. Computed from the IANA zone each time, so it follows daylight
     * saving instead of freezing the offset of the day of the lookup.
     */
    public function timezoneOffsetMinutes(?DateTimeInterface $at = null): ?int
    {
        $zone = $this->dateTimeZone();
        if ($zone === null) {
            return null;
        }

        $at = $at === null ? new DateTimeImmutable('now', $zone) : DateTimeImmutable::createFromInterface($at);

        return intdiv($zone->getOffset($at), 60);
    }

    public function dateTimeZone(): ?DateTimeZone
    {
        if ($this->timeZone === null) {
            return null;
        }

        try {
            return new DateTimeZone($this->timeZone);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The ISO 4217 code of the country's main currency.
     */
    public function currency(): ?string
    {
        return $this->countryCode === null ? null : Currencies::forCountry($this->countryCode);
    }

    private static function upper(?string $value): ?string
    {
        return $value === null ? null : strtoupper($value);
    }
}
