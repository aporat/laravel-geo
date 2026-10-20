<?php

declare(strict_types=1);

namespace Aporat\Geo\Exceptions;

use RuntimeException;

/**
 * `geo:update` could not fetch, unpack or verify a database.
 */
class DownloadException extends RuntimeException {}
