<?php

namespace App\Domains\Waste\Exceptions;

use RuntimeException;

/**
 * Raised for waste / scrap recording rule violations (missing category, reason
 * or a non-positive quantity).
 */
class WasteException extends RuntimeException
{
}
