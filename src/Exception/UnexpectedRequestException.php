<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Exception;

use LogicException;

use function sprintf;

/**
 * The code under test sent a request the {@see \Waffle\Commons\Testing\Http\ReplayClient}
 * has no answer for.
 *
 * It is a fault in the test's setup, not a transport failure, so it is not a
 * PSR-18 exception: code that handles upstream failures must not swallow it.
 */
final class UnexpectedRequestException extends LogicException
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
    ) {
        parent::__construct(sprintf('The replay client has no response left for %s %s.', $method, $uri));
    }
}
