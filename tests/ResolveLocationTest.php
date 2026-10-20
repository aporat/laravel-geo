<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests;

use Aporat\Geo\Facades\Geo;
use Aporat\Geo\Jobs\ResolveLocation;
use Aporat\Geo\Tests\Fixtures\Visitor;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Queue;

final class ResolveLocationTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Visitor::migrate();
    }

    public function test_resolves_and_applies_the_location(): void
    {
        $visitor = Visitor::query()->create(['ip_address' => '2.125.160.216']);

        ResolveLocation::for($visitor)->handle(Geo::getFacadeRoot());

        $visitor->refresh();
        self::assertSame('GB', $visitor->country_code);
        self::assertSame('Europe/London', $visitor->time_zone);
        self::assertContains($visitor->timezone_offset, [0, 60]);
    }

    public function test_hands_the_model_a_null_for_an_unknown_address(): void
    {
        $visitor = Visitor::query()->create(['ip_address' => '1.1.1.1']);

        (new ResolveLocation(Visitor::class, $visitor->id))->handle(Geo::getFacadeRoot());

        self::assertSame('ZZ', $visitor->refresh()->country_code);
    }

    public function test_skips_rows_without_an_address_or_already_gone(): void
    {
        $fake = Geo::fake();
        $visitor = Visitor::query()->create(['ip_address' => null]);

        (new ResolveLocation(Visitor::class, $visitor->id))->handle($fake);
        (new ResolveLocation(Visitor::class, 999))->handle($fake);

        $fake->assertNothingLocated();
        self::assertNull($visitor->refresh()->country_code);
    }

    public function test_dispatches_on_the_configured_connection_and_queue(): void
    {
        Queue::fake();
        config(['geo.queue.connection' => 'redis', 'geo.queue.queue' => 'geo']);
        $visitor = Visitor::query()->create(['ip_address' => '2.125.160.216']);

        ResolveLocation::dispatch(Visitor::class, $visitor->id);

        Queue::assertPushed(ResolveLocation::class, static fn (ResolveLocation $job): bool => $job->connection === 'redis'
            && $job->queue === 'geo'
            && $job->model === Visitor::class
            && $job->key === $visitor->id);
    }
}
