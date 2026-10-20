<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests\Console;

use Aporat\Geo\Jobs\ResolveLocation;
use Aporat\Geo\Tests\Fixtures\Visitor;
use Aporat\Geo\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Queue;

final class BackfillCommandTest extends TestCase
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

    public function test_resolves_the_rows_still_missing_a_country(): void
    {
        $known = Visitor::query()->create(['ip_address' => '2.125.160.216']);
        $unknown = Visitor::query()->create(['ip_address' => '1.1.1.1']);
        $blank = Visitor::query()->create(['ip_address' => null]);
        $done = Visitor::query()->create(['ip_address' => '2.125.160.216', 'country_code' => 'FR']);

        $this->artisan('geo:backfill', ['model' => Visitor::class, '--chunk' => 2])
            ->expectsOutputToContain('2 row(s) resolved, 1 without an address skipped')
            ->assertSuccessful();

        self::assertSame('GB', $known->refresh()->country_code);
        self::assertSame('ZZ', $unknown->refresh()->country_code);
        self::assertNull($blank->refresh()->country_code);
        self::assertSame('FR', $done->refresh()->country_code, 'already resolved rows are left alone');
    }

    public function test_can_queue_a_job_per_row(): void
    {
        Queue::fake();
        Visitor::query()->create(['ip_address' => '2.125.160.216']);
        Visitor::query()->create(['ip_address' => '81.2.69.160']);

        $this->artisan('geo:backfill', ['model' => '\\'.Visitor::class, '--queue' => true])
            ->expectsOutputToContain('2 row(s) queued')
            ->assertSuccessful();

        Queue::assertPushed(ResolveLocation::class, 2);
    }

    public function test_the_column_can_be_changed_or_dropped(): void
    {
        $done = Visitor::query()->create(['ip_address' => '2.125.160.216', 'country_code' => 'FR']);

        $this->artisan('geo:backfill', ['model' => Visitor::class, '--null' => ''])->assertSuccessful();

        self::assertSame('GB', $done->refresh()->country_code, 'with no column every row is resolved again');
    }

    public function test_reports_when_there_is_nothing_to_do(): void
    {
        $this->artisan('geo:backfill', ['model' => Visitor::class])
            ->expectsOutputToContain('Nothing to backfill')
            ->assertSuccessful();
    }

    public function test_refuses_a_class_that_is_not_a_geolocatable_model(): void
    {
        $this->artisan('geo:backfill', ['model' => self::class])
            ->expectsOutputToContain('is not an Eloquent model implementing')
            ->assertFailed();

        $this->artisan('geo:backfill', ['model' => 'App\\Nope'])->assertFailed();
    }
}
