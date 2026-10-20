<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests\Drivers;

use Aporat\Geo\Drivers\HeaderDriver;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class HeaderDriverTest extends TestCase
{
    private function driver(): HeaderDriver
    {
        return new HeaderDriver([
            'country' => ['CloudFront-Viewer-Country', 'CF-IPCountry'],
            'subdivision' => ['CloudFront-Viewer-Country-Region'],
            'city' => ['CloudFront-Viewer-City'],
            'postal_code' => ['CloudFront-Viewer-Postal-Code'],
            'latitude' => ['CloudFront-Viewer-Latitude'],
            'longitude' => ['CloudFront-Viewer-Longitude'],
            'time_zone' => ['CloudFront-Viewer-Time-Zone'],
            'asn' => ['CloudFront-Viewer-ASN'],
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(string $ip, array $headers): Request
    {
        $server = ['REMOTE_ADDR' => $ip];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/', 'GET', server: $server);
    }

    public function test_reads_what_the_edge_stamped(): void
    {
        $request = $this->request('8.8.8.8', [
            'CloudFront-Viewer-Country' => 'us',
            'CloudFront-Viewer-Country-Region' => 'WA',
            'CloudFront-Viewer-City' => 'Milton',
            'CloudFront-Viewer-Postal-Code' => '98354',
            'CloudFront-Viewer-Latitude' => '47.2513',
            'CloudFront-Viewer-Longitude' => '-122.3149',
            'CloudFront-Viewer-Time-Zone' => 'America/Los_Angeles',
            'CloudFront-Viewer-ASN' => '15169',
        ]);

        $location = $this->driver()->lookup('8.8.8.8', $request);

        self::assertNotNull($location);
        self::assertSame('US', $location->countryCode);
        self::assertSame('WA', $location->subdivisionCode);
        self::assertSame('Milton', $location->city);
        self::assertSame('98354', $location->postalCode);
        self::assertSame(47.2513, $location->latitude);
        self::assertSame(-122.3149, $location->longitude);
        self::assertSame('America/Los_Angeles', $location->timeZone);
        self::assertSame(15169, $location->asn);
        self::assertSame('header', $location->source);
        self::assertSame('US', $this->driver()->country('8.8.8.8', $request));
    }

    public function test_falls_through_the_header_names(): void
    {
        $request = $this->request('8.8.8.8', ['CF-IPCountry' => 'SE']);

        self::assertSame('SE', $this->driver()->lookup('8.8.8.8', $request)?->countryCode);
    }

    public function test_unknown_markers_answer_nothing(): void
    {
        foreach (['XX', 'T1', '', 'USA'] as $value) {
            self::assertNull($this->driver()->lookup('8.8.8.8', $this->request('8.8.8.8', ['CF-IPCountry' => $value])), $value);
        }
        self::assertNull($this->driver()->lookup('8.8.8.8', $this->request('8.8.8.8', [])));
    }

    public function test_only_describes_the_requests_own_client(): void
    {
        $request = $this->request('8.8.8.8', ['CF-IPCountry' => 'SE']);

        self::assertNull($this->driver()->lookup('1.1.1.1', $request));
        self::assertNull($this->driver()->country('1.1.1.1', $request));
        self::assertNull($this->driver()->lookup('8.8.8.8'));
    }
}
