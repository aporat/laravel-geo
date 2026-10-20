<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Driver chain
    |--------------------------------------------------------------------------
    |
    | The drivers Geo::locate() asks, in order. The first one with an answer
    | wins; one that has no data for the address, or fails, hands over to the
    | next. Each name has its own block below. `header` is free when a CDN
    | (CloudFront, Cloudflare) stamps the viewer's country on the request,
    | `maxmind` reads the local databases, `ipinfo` is an HTTP fallback.
    |
    */
    'drivers' => ['maxmind'],

    /*
    |--------------------------------------------------------------------------
    | Time budget
    |--------------------------------------------------------------------------
    |
    | Seconds one locate() may spend across the whole chain. A driver that
    | would start after the budget is spent is skipped. Local lookups take
    | microseconds; this is for the HTTP drivers.
    |
    */
    'total_timeout' => 2.0,

    /*
    |--------------------------------------------------------------------------
    | Failure reporting
    |--------------------------------------------------------------------------
    |
    | A driver that throws is reported through the exception handler before
    | the chain moves on. Off, failures are swallowed.
    |
    */
    'report_failures' => true,

    /*
    |--------------------------------------------------------------------------
    | MaxMind databases
    |--------------------------------------------------------------------------
    |
    | Local GeoLite2 / GeoIP2 .mmdb files under `path`, one per role, named
    | `<edition>.mmdb`. `city` answers the full location, `asn` adds the
    | network's AS number and organisation, and `country`, when set, is the
    | smaller file Geo::country() reads instead of the city one. A missing
    | file answers nothing for its role; `php artisan geo:status` shows what
    | is on disk. `geo:update` downloads every edition listed here with the
    | account id and license key from maxmind.com.
    |
    */
    'maxmind' => [
        'path' => storage_path('app/geo'),
        'editions' => [
            'city' => 'GeoLite2-City',
            'country' => null,
            'asn' => 'GeoLite2-ASN',
        ],
        // The names the city record is read in, in order of preference.
        'locales' => ['en'],
        // How often an open reader checks whether the file on disk changed
        // (geo:update swaps it atomically) and reopens it.
        'reopen_check_seconds' => 60,
        'account_id' => env('MAXMIND_ACCOUNT_ID'),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
        'download_url' => 'https://download.maxmind.com/geoip/databases/{edition}/download?suffix=tar.gz',
        'download_timeout' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Edge headers
    |--------------------------------------------------------------------------
    |
    | Headers a CDN sets from its own lookup. Only trusted when the request
    | really came through that edge: enable the driver on a host where the
    | CDN strips client-sent copies, or anyone can claim a country. The
    | driver answers only for the request's own client address.
    |
    */
    'header' => [
        'headers' => [
            'country' => ['CloudFront-Viewer-Country', 'CF-IPCountry'],
            'subdivision' => ['CloudFront-Viewer-Country-Region'],
            'subdivision_name' => ['CloudFront-Viewer-Country-Region-Name'],
            'city' => ['CloudFront-Viewer-City', 'CF-IPCity'],
            'postal_code' => ['CloudFront-Viewer-Postal-Code'],
            'latitude' => ['CloudFront-Viewer-Latitude', 'CF-IPLatitude'],
            'longitude' => ['CloudFront-Viewer-Longitude', 'CF-IPLongitude'],
            'time_zone' => ['CloudFront-Viewer-Time-Zone'],
            'asn' => ['CloudFront-Viewer-ASN'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ipinfo.io
    |--------------------------------------------------------------------------
    |
    | An HTTP provider, for hosts without a local database or as a fallback.
    | Answers are cached per address so a busy client costs one request per
    | TTL; a null `store` is the default cache store.
    |
    */
    'ipinfo' => [
        'token' => env('IPINFO_TOKEN'),
        'url' => 'https://ipinfo.io/{ip}/json',
        'timeout' => 2.0,
        'cache' => [
            'store' => null,
            'ttl' => 60 * 60 * 24 * 30,
            'prefix' => 'geo',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where the ResolveLocation job runs. Null keeps the application defaults.
    |
    */
    'queue' => [
        'connection' => null,
        'queue' => null,
    ],

];
