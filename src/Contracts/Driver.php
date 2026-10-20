<?php

declare(strict_types=1);

namespace Aporat\Geo\Contracts;

use Aporat\Geo\Exceptions\LookupException;
use Aporat\Geo\Location;
use Illuminate\Http\Request;

/**
 * One source of locations. The manager asks the chain's drivers in order
 * and keeps the first answer.
 */
interface Driver
{
    public function name(): string;

    /**
     * The location of a public, normalised address, or null when this
     * source has no data for it. The request is the one being served, for
     * drivers that read what an edge stamped on it.
     *
     * @throws LookupException when the source could not be consulted
     */
    public function lookup(string $ip, ?Request $request = null): ?Location;
}
