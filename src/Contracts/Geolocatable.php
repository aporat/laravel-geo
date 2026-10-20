<?php

declare(strict_types=1);

namespace Aporat\Geo\Contracts;

use Aporat\Geo\Location;

/**
 * A model whose location is resolved from an address it stores. The
 * ResolveLocation job and `geo:backfill` read the address, run the lookup
 * and hand the model the answer; what to persist is the model's business.
 */
interface Geolocatable
{
    /**
     * The address to resolve, null when the record has none yet.
     */
    public function geoIpAddress(): ?string;

    /**
     * Persist what the lookup found. Null means no driver had data for the
     * address; the model decides whether to store defaults so the record is
     * not resolved again.
     */
    public function applyLocation(?Location $location): void;
}
