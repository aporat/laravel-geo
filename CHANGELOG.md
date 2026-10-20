# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `Geo::locate()` / `Geo::country()` over an ordered driver chain, with a time budget and failure
  reporting; `Geo::extend()` for custom drivers; `$request->location()`.
- Drivers: `maxmind` (city, country and ASN files merged into one answer, readers reopened when the
  file on disk changes, `ext-maxminddb` picked up when present), `header` (CloudFront and Cloudflare
  viewer headers, for the request's own client only), `ipinfo` (HTTP, cached per address), `array`.
- `Location`: an immutable, array-round-trippable record with `timezoneOffsetMinutes()`,
  `currency()`, `countryIs()`, `merge()` and `with()`.
- `Ip`: canonical spelling, IPv4-mapped IPv6 reduction, and CIDR-based reserved-range checks.
- `geo:update`, `geo:status` and `geo:backfill`; the `Geolocatable` contract and the `ResolveLocation`
  job.
- `Geo::fake()` with address patterns and lookup assertions.
