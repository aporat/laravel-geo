<?php

declare(strict_types=1);

namespace Aporat\Geo\Exceptions;

use RuntimeException;

/**
 * A driver could not be consulted: a corrupt database, an HTTP failure, a
 * provider that refused. "No data for this address" is not an exception;
 * a driver answers null for that.
 */
class LookupException extends RuntimeException {}
