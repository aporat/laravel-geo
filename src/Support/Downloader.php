<?php

declare(strict_types=1);

namespace Aporat\Geo\Support;

use Aporat\Geo\Exceptions\DownloadException;
use GeoIp2\Database\Reader;
use Illuminate\Http\Client\Factory as HttpFactory;
use PharData;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Fetches one MaxMind edition through the download permalink, unpacks the
 * .mmdb from the tarball, checks it opens, and moves it into place with an
 * atomic rename so readers only ever see a complete file.
 */
class Downloader
{
    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly string $accountId,
        protected readonly string $licenseKey,
        protected readonly string $urlTemplate = 'https://download.maxmind.com/geoip/databases/{edition}/download?suffix=tar.gz',
        protected readonly int $timeout = 300,
    ) {}

    /**
     * Download an edition into the directory as `<edition>.mmdb`.
     *
     * @return array{path: string, build_epoch: int, bytes: int, database_type: string}
     *
     * @throws DownloadException
     */
    public function download(string $edition, string $directory): array
    {
        $directory = rtrim($directory, '/\\');
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new DownloadException(sprintf('Cannot create %s.', $directory));
        }

        $destination = $directory.DIRECTORY_SEPARATOR.$edition.'.mmdb';
        $archive = $this->temporaryPath($directory, $edition, '.tar.gz');
        $tar = $this->temporaryPath($directory, $edition, '.tar');
        $extracted = $this->temporaryPath($directory, $edition, '.mmdb.tmp');

        try {
            $this->fetch($edition, $archive);
            $this->gunzip($archive, $tar);
            $this->extractDatabase($tar, $extracted);
            $metadata = $this->verify($edition, $extracted);

            if (! @rename($extracted, $destination)) {
                throw new DownloadException(sprintf('Cannot move the %s database into %s.', $edition, $destination));
            }
            @chmod($destination, 0644);

            return [
                'path' => $destination,
                'build_epoch' => $metadata['build_epoch'],
                'bytes' => (int) filesize($destination),
                'database_type' => $metadata['database_type'],
            ];
        } finally {
            foreach ([$archive, $tar, $extracted] as $temporary) {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }
    }

    public function url(string $edition): string
    {
        return str_replace('{edition}', rawurlencode($edition), $this->urlTemplate);
    }

    protected function fetch(string $edition, string $archive): void
    {
        try {
            $response = $this->http
                ->withBasicAuth($this->accountId, $this->licenseKey)
                ->timeout($this->timeout)
                ->withOptions(['stream' => true])
                ->get($this->url($edition));
        } catch (Throwable $e) {
            throw new DownloadException(sprintf('Downloading %s failed: %s', $edition, $e->getMessage()), 0, $e);
        }

        if (! $response->successful()) {
            $hint = match ($response->status()) {
                401 => ' (check MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY)',
                403 => ' (the account may not be licensed for this edition)',
                404 => ' (unknown edition id)',
                default => '',
            };

            throw new DownloadException(sprintf('Downloading %s answered HTTP %d%s.', $edition, $response->status(), $hint));
        }

        $handle = @fopen($archive, 'wb');
        if ($handle === false) {
            throw new DownloadException(sprintf('Cannot write %s.', $archive));
        }

        $body = $response->toPsrResponse()->getBody();
        while (! $body->eof()) {
            fwrite($handle, $body->read(1024 * 1024));
        }
        fclose($handle);
    }

    protected function gunzip(string $archive, string $tar): void
    {
        $in = @gzopen($archive, 'rb');
        $out = @fopen($tar, 'wb');
        if ($in === false || $out === false) {
            throw new DownloadException(sprintf('Cannot decompress %s.', $archive));
        }

        while (! gzeof($in)) {
            $chunk = gzread($in, 1024 * 1024);
            if ($chunk === false) {
                throw new DownloadException(sprintf('%s is not a gzip archive.', $archive));
            }
            fwrite($out, $chunk);
        }
        gzclose($in);
        fclose($out);
    }

    /**
     * Copy the single .mmdb the tarball carries (MaxMind nests it in a
     * `<edition>_<date>/` directory) to the given path.
     */
    protected function extractDatabase(string $tar, string $destination): void
    {
        try {
            $phar = new PharData($tar);
            /** @var SplFileInfo $entry */
            foreach (new RecursiveIteratorIterator($phar) as $entry) {
                if (! str_ends_with($entry->getFilename(), '.mmdb')) {
                    continue;
                }

                if (! @copy($entry->getPathname(), $destination)) {
                    throw new DownloadException(sprintf('Cannot extract %s.', $entry->getFilename()));
                }

                return;
            }
        } catch (DownloadException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DownloadException(sprintf('Cannot read the archive: %s', $e->getMessage()), 0, $e);
        }

        throw new DownloadException('The archive holds no .mmdb file.');
    }

    /**
     * @return array{build_epoch: int, database_type: string}
     */
    protected function verify(string $edition, string $path): array
    {
        try {
            $reader = new Reader($path);
            $metadata = $reader->metadata();
            $reader->close();
        } catch (Throwable $e) {
            throw new DownloadException(sprintf('The downloaded %s database does not open: %s', $edition, $e->getMessage()), 0, $e);
        }

        return ['build_epoch' => $metadata->buildEpoch, 'database_type' => $metadata->databaseType];
    }

    protected function temporaryPath(string $directory, string $edition, string $suffix): string
    {
        return $directory.DIRECTORY_SEPARATOR.'.'.$edition.'.'.bin2hex(random_bytes(4)).$suffix;
    }
}
