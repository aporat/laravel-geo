<?php

declare(strict_types=1);

namespace Aporat\Geo\Tests\Fixtures;

use Aporat\Geo\Contracts\Geolocatable;
use Aporat\Geo\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @property int $id
 * @property string|null $ip_address
 * @property string|null $country_code
 * @property string|null $time_zone
 * @property int|null $timezone_offset
 */
final class Visitor extends Model implements Geolocatable
{
    public $timestamps = false;

    protected $table = 'visitors';

    protected $guarded = [];

    public static function migrate(): void
    {
        Schema::create('visitors', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('ip_address')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('time_zone')->nullable();
            $table->integer('timezone_offset')->nullable();
        });
    }

    public function geoIpAddress(): ?string
    {
        return $this->ip_address;
    }

    public function applyLocation(?Location $location): void
    {
        $this->forceFill([
            'country_code' => $location?->countryCode ?? 'ZZ',
            'time_zone' => $location?->timeZone,
            'timezone_offset' => $location?->timezoneOffsetMinutes(),
        ])->save();
    }
}
