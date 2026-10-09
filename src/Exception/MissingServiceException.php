<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Exception;

use LogicException;
use Throwable;

use function sprintf;

/**
 * The kernel's container does not provide a service the testing bridge needs,
 * such as the PSR-17 factories it builds requests with. The application's kernel
 * factory registers them.
 */
final class MissingServiceException extends LogicException
{
    public function __construct(
        public readonly string $service,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('The kernel container provides no "%s"; register one in the application kernel factory.', $service),
            0,
            $previous,
        );
    }
}
