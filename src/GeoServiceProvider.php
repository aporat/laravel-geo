<?php

declare(strict_types=1);

namespace Aporat\Geo;

use Aporat\Geo\Console\BackfillCommand;
use Aporat\Geo\Console\StatusCommand;
use Aporat\Geo\Console\UpdateCommand;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class GeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/geo.php', 'geo');

        $this->app->singleton('geo', fn (Container $app): GeoManager => new GeoManager($app));
        $this->app->alias('geo', GeoManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/geo.php' => config_path('geo.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([UpdateCommand::class, StatusCommand::class, BackfillCommand::class]);
        }

        // $request->location(): the client's location, through the trusted
        // proxy chain the request already resolved its ip() from.
        Request::macro('location', function (): ?Location {
            /** @var Request $this */
            return app(GeoManager::class)->locate(null, $this);
        });
    }
}
