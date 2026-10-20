<?php

declare(strict_types=1);

namespace Aporat\Geo\Contracts;

use Aporat\Geo\Exceptions\LookupException;
use Illuminate\Http\Request;

/**
 * A driver that can answer the country alone more cheaply than a full
 * lookup. Geo::country() prefers this over lookup() where it is offered.
 */
interface CountryLookup
{
    /**
     * The ISO 3166-1 alpha-2 code, or null when unknown.
     *
     * @throws LookupException
     */
    public function country(string $ip, ?Request $request = null): ?string;
}
