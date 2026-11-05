<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Exception;

use Throwable;
use UnexpectedValueException;

/**
 * A response body that a test reads as JSON is not a JSON object or list.
 */
final class InvalidJsonException extends UnexpectedValueException
{
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct('The response body is not a JSON object or list: ' . $reason, 0, $previous);
    }
}
