<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests\Console;

use Aporat\Geo\Facades\Geo;
use Aporat\Geo\Tests\TestCase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Phar;
use PharData;

final class UpdateCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = $this->temporaryDirectory();
        config([
            'geo.maxmind.path' => $this->directory.'/db',
            'geo.maxmind.editions' => ['city' => 'GeoIP2-City-Test', 'country' => null, 'asn' => 'GeoLite2-ASN-Test'],
            'geo.maxmind.account_id' => '123456',
            'geo.maxmind.license_key' => 'license',
        ]);
    }

    /**
     * A tarball shaped like MaxMind's: the .mmdb inside a dated directory.
     */
    private function archive(string $edition): string
    {
        $tar = $this->directory.'/'.$edition.'.tar';
        $phar = new PharData($tar);
        $phar->addFile($this->fixtures.'/'.$edition.'.mmdb', $edition.'_20261020/'.$edition.'.mmdb');
        $phar->addFromString($edition.'_20261020/LICENSE.txt', 'test');
        $phar->compress(Phar::GZ);

        return (string) file_get_contents($tar.'.gz');
    }

    public function test_downloads_every_configured_edition(): void
    {
        Http::fake([
            'download.maxmind.com/geoip/databases/GeoIP2-City-Test/download*' => Http::response($this->archive('GeoIP2-City-Test')),
            'download.maxmind.com/geoip/databases/GeoLite2-ASN-Test/download*' => Http::response($this->archive('GeoLite2-ASN-Test')),
        ]);

        $this->artisan('geo:update')
            ->expectsOutputToContain('GeoIP2-City-Test')
            ->expectsOutputToContain('GeoLite2-ASN-Test')
            ->assertSuccessful();

        self::assertFileExists($this->directory.'/db/GeoIP2-City-Test.mmdb');
        self::assertFileExists($this->directory.'/db/GeoLite2-ASN-Test.mmdb');
        self::assertSame([], glob($this->directory.'/db/.*.tmp') ?: [], 'no temporary files left behind');
        self::assertSame('Boxford', Geo::locate('2.125.160.216')?->city, 'the new file is read straight away');

        Http::assertSentCount(2);
        Http::assertSent(static fn (ClientRequest $request): bool => str_ends_with($request->url(), 'GeoIP2-City-Test/download?suffix=tar.gz')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('123456:license')));
    }

    public function test_downloads_only_the_requested_edition(): void
    {
        Http::fake(['download.maxmind.com/*' => Http::response($this->archive('GeoLite2-ASN-Test'))]);

        $this->artisan('geo:update', ['--edition' => ['GeoLite2-ASN-Test']])->assertSuccessful();

        Http::assertSentCount(1);
        self::assertFileExists($this->directory.'/db/GeoLite2-ASN-Test.mmdb');
        self::assertFileDoesNotExist($this->directory.'/db/GeoIP2-City-Test.mmdb');
    }

    public function test_refuses_without_credentials(): void
    {
        config(['geo.maxmind.license_key' => null]);
        Http::fake();

        $this->artisan('geo:update')
            ->expectsOutputToContain('MAXMIND_LICENSE_KEY')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_reports_a_refused_download_and_keeps_the_old_file(): void
    {
        mkdir($this->directory.'/db');
        copy($this->fixtures.'/GeoIP2-City-Test.mmdb', $this->directory.'/db/GeoIP2-City-Test.mmdb');
        Http::fake([
            'download.maxmind.com/geoip/databases/GeoIP2-City-Test/download*' => Http::response('denied', 401),
            'download.maxmind.com/geoip/databases/GeoLite2-ASN-Test/download*' => Http::response($this->archive('GeoLite2-ASN-Test')),
        ]);

        $this->artisan('geo:update')
            ->expectsOutputToContain('HTTP 401 (check MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY)')
            ->assertFailed();

        self::assertFileEquals($this->fixtures.'/GeoIP2-City-Test.mmdb', $this->directory.'/db/GeoIP2-City-Test.mmdb');
        self::assertFileExists($this->directory.'/db/GeoLite2-ASN-Test.mmdb', 'the other edition still updated');
    }

    public function test_rejects_an_archive_without_a_database(): void
    {
        $tar = $this->directory.'/empty.tar';
        $phar = new PharData($tar);
        $phar->addFromString('README', 'nothing here');
        $phar->compress(Phar::GZ);
        Http::fake(['download.maxmind.com/*' => Http::response((string) file_get_contents($tar.'.gz'))]);

        $this->artisan('geo:update', ['--edition' => ['GeoIP2-City-Test']])
            ->expectsOutputToContain('no .mmdb file')
            ->assertFailed();

        self::assertFileDoesNotExist($this->directory.'/db/GeoIP2-City-Test.mmdb');
    }

    public function test_rejects_a_corrupt_database(): void
    {
        $tar = $this->directory.'/corrupt.tar';
        $phar = new PharData($tar);
        $phar->addFromString('GeoIP2-City-Test_20261020/GeoIP2-City-Test.mmdb', 'not a database');
        $phar->compress(Phar::GZ);
        Http::fake(['download.maxmind.com/*' => Http::response((string) file_get_contents($tar.'.gz'))]);

        $this->artisan('geo:update', ['--edition' => ['GeoIP2-City-Test']])
            ->expectsOutputToContain('does not open')
            ->assertFailed();

        self::assertFileDoesNotExist($this->directory.'/db/GeoIP2-City-Test.mmdb');
    }

    public function test_rejects_something_that_is_not_gzip(): void
    {
        Http::fake(['download.maxmind.com/*' => Http::response('<html>login</html>')]);

        $this->artisan('geo:update', ['--edition' => ['GeoIP2-City-Test']])
            ->assertFailed();

        self::assertFileDoesNotExist($this->directory.'/db/GeoIP2-City-Test.mmdb');
    }
}
