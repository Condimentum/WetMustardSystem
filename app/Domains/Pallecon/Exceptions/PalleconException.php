<?php

namespace App\Domains\Pallecon\Exceptions;

use RuntimeException;

/**
 * Raised for pallecon container rule violations: capacity/overfill breaches,
 * filling a sealed container, or reusing a serial that is still active.
 */
class PalleconException extends RuntimeException
{
}
