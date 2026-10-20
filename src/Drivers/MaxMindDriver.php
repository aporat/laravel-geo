<?php

declare(strict_types=1);

namespace Aporat\Geo\Drivers;

use Aporat\Geo\Contracts\CountryLookup;
use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\Exceptions\DatabaseException;
use Aporat\Geo\Location;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use GeoIp2\Model\City;
use GeoIp2\Model\Country;
use Illuminate\Http\Request;
use MaxMind\Db\Reader\Metadata;
use Throwable;

/**
 * Reads the local MaxMind databases: the city (or country) file for the
 * place, the ASN file for the network, merged into one Location. Readers
 * stay open for the life of the process and reopen themselves when
 * `geo:update` swaps the file underneath them, so a long-lived queue
 * worker picks up a new build without a restart.
 */
class MaxMindDriver implements CountryLookup, Driver
{
    public const string ROLE_CITY = 'city';

    public const string ROLE_COUNTRY = 'country';

    public const string ROLE_ASN = 'asn';

    /** @var array<string, array{reader: Reader, mtime: int, checked_at: float}> */
    protected array $readers = [];

    /**
     * @param  array<string, string|null>  $editions  role => edition id (`GeoLite2-City`, ...)
     * @param  list<string>  $locales
     */
    public function __construct(
        protected readonly string $path,
        protected readonly array $editions,
        protected readonly array $locales = ['en'],
        protected readonly float $reopenCheckSeconds = 60,
    ) {}

    public function name(): string
    {
        return 'maxmind';
    }

    public function lookup(string $ip, ?Request $request = null): ?Location
    {
        $place = $this->place($ip);
        $network = $this->network($ip);

        if ($place === null && $network === null) {
            return null;
        }

        return ($place ?? new Location(ip: $ip, source: $this->name()))->merge($network);
    }

    public function country(string $ip, ?Request $request = null): ?string
    {
        $reader = $this->reader(self::ROLE_COUNTRY);
        if ($reader === null) {
            return $this->place($ip)?->countryCode;
        }

        try {
            return $reader->country($ip)->country->isoCode;
        } catch (AddressNotFoundException) {
            return null;
        } catch (Throwable $e) {
            throw $this->failure(self::ROLE_COUNTRY, $e);
        }
    }

    /**
     * The city record, or the country record when only that file exists.
     */
    protected function place(string $ip): ?Location
    {
        $city = $this->reader(self::ROLE_CITY);
        if ($city !== null) {
            try {
                return $this->fromCity($ip, $city->city($ip));
            } catch (AddressNotFoundException) {
                return null;
            } catch (Throwable $e) {
                throw $this->failure(self::ROLE_CITY, $e);
            }
        }

        $country = $this->reader(self::ROLE_COUNTRY);
        if ($country !== null) {
            try {
                return $this->fromCountry($ip, $country->country($ip));
            } catch (AddressNotFoundException) {
                return null;
            } catch (Throwable $e) {
                throw $this->failure(self::ROLE_COUNTRY, $e);
            }
        }

        return null;
    }

    protected function network(string $ip): ?Location
    {
        $reader = $this->reader(self::ROLE_ASN);
        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->asn($ip);

            return new Location(
                ip: $ip,
                asn: $record->autonomousSystemNumber,
                asOrganization: $record->autonomousSystemOrganization,
                source: $this->name(),
            );
        } catch (AddressNotFoundException) {
            return null;
        } catch (Throwable $e) {
            throw $this->failure(self::ROLE_ASN, $e);
        }
    }

    protected function fromCity(string $ip, City $record): Location
    {
        return new Location(
            ip: $ip,
            countryCode: $record->country->isoCode,
            countryName: $record->country->name,
            continentCode: $record->continent->code,
            isInEu: $record->country->isInEuropeanUnion,
            subdivisionCode: $record->mostSpecificSubdivision->isoCode,
            subdivisionName: $record->mostSpecificSubdivision->name,
            city: $record->city->name,
            postalCode: $record->postal->code,
            latitude: $record->location->latitude,
            longitude: $record->location->longitude,
            accuracyRadius: $record->location->accuracyRadius,
            timeZone: $record->location->timeZone,
            isAnycast: $record->traits->isAnycast,
            source: $this->name(),
        );
    }

    protected function fromCountry(string $ip, Country $record): Location
    {
        return new Location(
            ip: $ip,
            countryCode: $record->country->isoCode,
            countryName: $record->country->name,
            continentCode: $record->continent->code,
            isInEu: $record->country->isInEuropeanUnion,
            isAnycast: $record->traits->isAnycast,
            source: $this->name(),
        );
    }

    /**
     * The edition configured for a role, null when the role is unused.
     */
    public function edition(string $role): ?string
    {
        $edition = $this->editions[$role] ?? null;

        return is_string($edition) && $edition !== '' ? $edition : null;
    }

    /**
     * Every edition in use, without duplicates.
     *
     * @return list<string>
     */
    public function editions(): array
    {
        return array_values(array_unique(array_filter(array_map($this->edition(...), array_keys($this->editions)))));
    }

    public function directory(): string
    {
        return rtrim($this->path, '/\\');
    }

    /**
     * Where a role's database lives, null when the role is unused.
     */
    public function databasePath(string $role): ?string
    {
        $edition = $this->edition($role);

        return $edition === null ? null : $this->directory().DIRECTORY_SEPARATOR.$edition.'.mmdb';
    }

    /**
     * The open database's metadata, null when the file is absent.
     *
     * @throws DatabaseException
     */
    public function metadata(string $role): ?Metadata
    {
        return $this->reader($role)?->metadata();
    }

    /**
     * Whether lookups go through MaxMind's C extension.
     */
    public static function usesExtension(): bool
    {
        return extension_loaded('maxminddb');
    }

    /**
     * The reader for a role, opened on first use and reopened when the file
     * on disk changed since. Null when the role is unused or its file is
     * absent, so a missing database answers nothing rather than failing
     * every request.
     *
     * @throws DatabaseException when the file exists but cannot be opened
     */
    protected function reader(string $role): ?Reader
    {
        $path = $this->databasePath($role);
        if ($path === null) {
            return null;
        }

        $open = $this->readers[$role] ?? null;
        $now = microtime(true);
        if ($open !== null && $now - $open['checked_at'] < $this->reopenCheckSeconds) {
            return $open['reader'];
        }

        clearstatcache(true, $path);
        $mtime = is_file($path) ? filemtime($path) : false;
        if ($mtime === false) {
            if ($open !== null) {
                unset($this->readers[$role]);
                $this->close($open['reader']);
            }

            return null;
        }

        if ($open !== null && $open['mtime'] === $mtime) {
            $this->readers[$role]['checked_at'] = $now;

            return $open['reader'];
        }

        try {
            $reader = new Reader($path, $this->locales);
        } catch (Throwable $e) {
            throw $this->failure($role, $e);
        }

        if ($open !== null) {
            $this->close($open['reader']);
        }

        $this->readers[$role] = ['reader' => $reader, 'mtime' => $mtime, 'checked_at' => $now];

        return $reader;
    }

    /**
     * Drop every open reader; the next lookup reopens from disk.
     */
    public function reset(): void
    {
        foreach ($this->readers as $open) {
            $this->close($open['reader']);
        }
        $this->readers = [];
    }

    protected function close(Reader $reader): void
    {
        try {
            $reader->close();
        } catch (Throwable) {
            // Already closed, or the extension's reader has no handle to release.
        }
    }

    protected function failure(string $role, Throwable $e): DatabaseException
    {
        return new DatabaseException(sprintf('MaxMind %s database %s: %s', $role, $this->databasePath($role) ?? '?', $e->getMessage()), 0, $e);
    }
}
