# Laravel Geo

IP geolocation for Laravel: an ordered chain of sources (edge headers, local MaxMind databases, HTTP providers), one typed `Location` whatever answered, the database download from the terminal, a job and a backfill for models that store an address, and a fake for tests.

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-geo.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-geo)
[![Downloads](https://img.shields.io/packagist/dt/aporat/laravel-geo.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-geo)
[![codecov](https://codecov.io/github/aporat/laravel-geo/graph/badge.svg)](https://codecov.io/github/aporat/laravel-geo)
[![Laravel Version](https://img.shields.io/badge/Laravel-13.x-orange.svg?style=flat-square)](https://laravel.com/docs/13.x)
![GitHub Actions](https://img.shields.io/github/actions/workflow/status/aporat/laravel-geo/ci.yml?style=flat-square)
[![License](https://img.shields.io/github/license/aporat/laravel-geo?style=flat-square)](https://github.com/aporat/laravel-geo/blob/main/LICENSE)

---

## ✨ Features

- `Geo::locate($ip)` runs the configured drivers in order and keeps the first answer: `header` reads what CloudFront or Cloudflare stamped on the request, `maxmind` reads the local GeoLite2 / GeoIP2 files, `ipinfo` asks ipinfo.io over HTTP. Add your own with `Geo::extend()`
- One immutable `Location` whoever answered: country, continent, EU membership, subdivision, city, postal code, coordinates with accuracy radius, IANA time zone, AS number and organisation, anycast flag. `timezoneOffsetMinutes()` is computed from the zone at the instant you ask, so it follows daylight saving; `currency()` maps the country to its ISO 4217 code
- Reserved addresses (private, loopback, link-local, carrier-grade NAT, documentation) are never looked up, with real CIDR checks for both families; IPv4-mapped IPv6 addresses are reduced to the IPv4 they carry
- The MaxMind readers stay open for the life of the process and reopen themselves when the file on disk changes, so a queue worker picks up a new build without a restart. The city and ASN files are merged into one answer. With `ext-maxminddb` installed, the same reads go through MaxMind's C extension
- Answers are memoised per address in the process; the HTTP driver is additionally cached per address in any cache store, misses included. Local lookups never touch the cache: they are faster than the round trip
- `php artisan geo:update` downloads the editions you use with your MaxMind account id and license key, verifies each file opens, and swaps it in with an atomic rename. `geo:status` shows what is on disk and how old it is
- A `Geolocatable` contract for models that store an address, a queued `ResolveLocation` job that resolves one, and `geo:backfill` for every row still missing its location
- `$request->location()` for the current client, through the proxies the request already trusts
- `Geo::fake()` with address patterns, `assertLocated`, `assertNotLocated` and `assertNothingLocated`

---

## 🛠 Installation

```bash
composer require aporat/laravel-geo
```

Requires PHP 8.4+ and Laravel 12 or 13. Publish the config:

```bash
php artisan vendor:publish --tag=config --provider="Aporat\Geo\GeoServiceProvider"
```

Then get the databases. Create a free [MaxMind account](https://www.maxmind.com/en/geolite2/signup), generate a license key, put both in `.env`, and download:

```bash
MAXMIND_ACCOUNT_ID=123456
MAXMIND_LICENSE_KEY=xxxxxxxxxxxxxxxx
```

```bash
php artisan geo:update
php artisan geo:status
```

The files land in `storage/app/geo/` by default. GeoLite2 is rebuilt twice a week; run `geo:update` on a schedule, in your image build, or wherever a weekly download fits. For many lookups per second, install the C extension (`pie install maxmind-db/reader-ext`, or `pecl install maxminddb`): the package notices and nothing else changes.

---

## ⚙️ Configuration

```php
return [
    // The sources, in order. The first one with an answer wins.
    'drivers' => ['maxmind'],

    // Seconds one lookup may spend across the whole chain.
    'total_timeout' => 2.0,

    'maxmind' => [
        'path' => storage_path('app/geo'),
        'editions' => [
            'city' => 'GeoLite2-City',   // the full location
            'country' => null,           // a smaller file for Geo::country(), optional
            'asn' => 'GeoLite2-ASN',     // network number and organisation, optional
        ],
        'account_id' => env('MAXMIND_ACCOUNT_ID'),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
    ],

    'header' => [
        'headers' => [
            'country' => ['CloudFront-Viewer-Country', 'CF-IPCountry'],
            // ... city, postal code, coordinates, time zone, ASN
        ],
    ],

    'ipinfo' => [
        'token' => env('IPINFO_TOKEN'),
        'timeout' => 2.0,
        'cache' => ['store' => null, 'ttl' => 60 * 60 * 24 * 30],
    ],
];
```

Behind a CDN that stamps the viewer's country, put `header` first: `['header', 'maxmind']` answers the country for free and only opens the database for addresses the edge left blank. Without one, leave it out: the driver trusts the headers, and anyone can send them. For a host without local files, `['ipinfo']` alone works; an HTTP driver as the last entry is a fallback for addresses the databases do not know.

A paid GeoIP2 edition is a change of name: `'city' => 'GeoIP2-City'`.

---

## 🚀 Looking up

```php
use Aporat\Geo\Facades\Geo;

$location = Geo::locate('81.2.69.160');

$location->countryCode;        // "GB"
$location->countryName;        // "United Kingdom"
$location->isInEu;             // false
$location->subdivisionCode;    // "ENG"
$location->city;               // "London"
$location->latitude;           // 51.5142
$location->accuracyRadius;     // 100 (km)
$location->timeZone;           // "Europe/London"
$location->asn;                // 20712
$location->asOrganization;     // "Andrews & Arnold Ltd"
$location->source;             // "maxmind"

$location->timezoneOffsetMinutes();                       // 60 in summer, 0 in winter
$location->timezoneOffsetMinutes(new DateTime('2026-01-15')); // 0
$location->currency();                                    // "GBP"
$location->countryIs('GB', 'IE');                         // true
$location->toArray();                                     // snake_case, round-trips through Location::make()

Geo::country('81.2.69.160');   // "GB", through the cheapest path each driver offers
Geo::locate();                 // the current request's client
$request->location();          // the same, as a request macro
```

`locate()` answers null for an address no driver knows, and for every reserved address without asking any driver. A driver that fails is reported through the exception handler (`report_failures`) and the chain moves on.

Other sources go through `Geo::extend()`:

```php
use Aporat\Geo\Contracts\Driver;
use Aporat\Geo\Drivers\ArrayDriver;

Geo::extend('office', fn ($app, array $config): Driver => new ArrayDriver([
    '203.0.113.*' => ['country_code' => 'US', 'city' => 'Austin', 'time_zone' => 'America/Chicago'],
]));
```

and `'drivers' => ['office', 'maxmind']`. A driver gets its config block (`geo.office`) and implements `lookup(string $ip, ?Request $request): ?Location`, returning null for "no data" and throwing `LookupException` for "could not ask". Implement `CountryLookup` too when the country alone is cheaper. A `cache` block on any driver's config wraps it in the per-address cache.

---

## 🧭 Models that store an address

Implement `Geolocatable` on the model, dispatch the job when the address is set, and the row is resolved off the request path:

```php
use Aporat\Geo\Contracts\Geolocatable;
use Aporat\Geo\Jobs\ResolveLocation;
use Aporat\Geo\Location;

class Account extends Model implements Geolocatable
{
    protected static function booted(): void
    {
        static::created(fn (self $account) => ResolveLocation::dispatch(self::class, $account->id)->afterCommit());
    }

    public function geoIpAddress(): ?string
    {
        return $this->ip_address;
    }

    public function applyLocation(?Location $location): void
    {
        $this->update([
            // Store something for a miss too, so the row is not resolved again and again.
            'country_code' => $location?->countryCode ?? 'US',
            'timezone_offset' => $location?->timezoneOffsetMinutes() ?? -300,
        ]);
    }
}
```

The job holds the class and key, not the model, so a row deleted before it runs is a no-op. `geo.queue` picks its connection and queue. Rows that were never resolved, or that predate the job:

```bash
php artisan geo:backfill "App\Models\Account"              # rows where country_code is null, resolved inline
php artisan geo:backfill "App\Models\Account" --queue      # one ResolveLocation job per row
php artisan geo:backfill "App\Models\Account" --null=time_zone --chunk=500
```

---

## 🧪 Testing

```php
use Aporat\Geo\Facades\Geo;
use Aporat\Geo\Location;

$fake = Geo::fake([
    '203.0.113.7' => new Location(ip: '203.0.113.7', countryCode: 'US', city: 'Austin', timeZone: 'America/Chicago'),
    '10.*' => ['country_code' => 'SE'],
    '*' => null,
]);

Geo::locate('10.1.2.3')->countryCode;   // "SE"
Geo::locate('8.8.8.8');                 // null

$fake->assertLocated('10.1.2.3')
    ->assertNotLocated('8.8.4.4')
    ->assertNothingLocated();           // fails: two lookups were made
```

The fake answers for reserved addresses too, so the test client on `127.0.0.1` can be placed anywhere. `$request->location()` and the `ResolveLocation` job go through it as well.

The package's own tests run against MaxMind's published test databases in `tests/Fixtures`, a few KB each; a real `GeoLite2-City.mmdb` is about 60 MB.

---

## 🖥 Console

| Command | Does |
|---|---|
| `geo:update [--edition=GeoLite2-City]` | Download every configured edition (or the named ones) with the MaxMind credentials, verify, and swap in atomically. Fails, and keeps the old file, when a download is refused or does not open |
| `geo:status` | The driver chain, the reader in use, and each role's file with its build date and age |
| `geo:backfill {model} [--null=country_code] [--chunk=200] [--queue]` | Resolve every row of a `Geolocatable` model still missing its location |

---

## 📄 License

MIT. The test databases under `tests/Fixtures` are MaxMind's, from [maxmind/MaxMind-DB](https://github.com/maxmind/MaxMind-DB), under their MIT / Apache 2.0 licence. GeoLite2 data itself is subject to the [GeoLite2 End User License Agreement](https://www.maxmind.com/en/geolite2/eula).
