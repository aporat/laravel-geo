<?php

declare(strict_types=1);

namespace Aporat\Geo\Jobs;

use Aporat\Geo\Contracts\Geolocatable;
use Aporat\Geo\GeoManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;

/**
 * Resolve one Geolocatable model's location off the request path. Holds
 * the class and key rather than the model, so a row deleted before the
 * job runs is a no-op instead of a ModelNotFoundException.
 */
final class ResolveLocation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  class-string<Model&Geolocatable>  $model
     */
    public function __construct(
        public readonly string $model,
        public readonly int|string $key,
    ) {
        $connection = config('geo.queue.connection');
        $queue = config('geo.queue.queue');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /**
     * @param  Model&Geolocatable  $model
     */
    public static function for(Model $model): self
    {
        $key = $model->getKey();
        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException(sprintf('%s has no int or string primary key to resolve by.', $model::class));
        }

        return new self($model::class, $key);
    }

    public function handle(GeoManager $geo): void
    {
        $instance = $this->model::query()->find($this->key);
        if (! $instance instanceof Geolocatable) {
            return;
        }

        $ip = $instance->geoIpAddress();
        if ($ip === null || trim($ip) === '') {
            return;
        }

        $instance->applyLocation($geo->locate($ip));
    }
}
