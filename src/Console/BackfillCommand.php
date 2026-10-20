<?php

declare(strict_types=1);

namespace Aporat\Geo\Console;

use Aporat\Geo\Contracts\Geolocatable;
use Aporat\Geo\GeoManager;
use Aporat\Geo\Jobs\ResolveLocation;
use Aporat\Geo\Support\Config;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolve every row of a Geolocatable model still missing its location.
 */
class BackfillCommand extends Command
{
    protected $signature = 'geo:backfill
        {model : The Geolocatable Eloquent model class}
        {--null=country_code : Only rows where this column is null}
        {--chunk=200 : Rows per chunk}
        {--queue : Dispatch a ResolveLocation job per row instead of resolving inline}';

    protected $description = 'Resolve the location of every row of a model that still lacks one';

    public function handle(GeoManager $geo, Dispatcher $bus): int
    {
        $class = ltrim(Config::string($this->argument('model')), '\\');
        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Geolocatable::class)) {
            $this->error(sprintf('%s is not an Eloquent model implementing %s.', $class, Geolocatable::class));

            return self::FAILURE;
        }
        /** @var class-string<Model&Geolocatable> $class */
        $column = Config::string($this->option('null'));
        $chunk = max(1, Config::int($this->option('chunk'), 200));
        $queue = (bool) $this->option('queue');

        $query = $class::query();
        if ($column !== '') {
            $query->whereNull($column);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->components->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $resolved = 0;
        $skipped = 0;

        $query->chunkById($chunk, function (Collection $rows) use ($geo, $bus, $queue, $bar, &$resolved, &$skipped): void {
            /** @var Model&Geolocatable $row */
            foreach ($rows as $row) {
                $ip = $row->geoIpAddress();
                if ($ip === null || trim($ip) === '') {
                    $skipped++;
                } elseif ($queue) {
                    $bus->dispatch(ResolveLocation::for($row));
                    $resolved++;
                } else {
                    $row->applyLocation($geo->locate($ip));
                    $resolved++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->components->info(sprintf('%d row(s) %s, %d without an address skipped.', $resolved, $queue ? 'queued' : 'resolved', $skipped));

        return self::SUCCESS;
    }
}
