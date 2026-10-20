<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests;

use Aporat\Geo\GeoServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $fixtures = __DIR__.'/Fixtures';

    /** @var list<string> */
    private array $temporaryDirectories = [];

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GeoServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('geo.maxmind.path', $this->fixtures);
        $app['config']->set('geo.maxmind.editions', [
            'city' => 'GeoIP2-City-Test',
            'country' => 'GeoLite2-Country-Test',
            'asn' => 'GeoLite2-ASN-Test',
        ]);
        $app['config']->set('geo.maxmind.reopen_check_seconds', 0);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }
        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    /**
     * A fresh empty directory, removed after the test.
     */
    protected function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/laravel-geo-'.bin2hex(random_bytes(6));
        mkdir($directory, 0755, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
