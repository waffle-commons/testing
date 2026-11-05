<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Exception;

use LogicException;
use Waffle\Commons\Contracts\Queue\Exception\QueueExceptionInterface;

use function sprintf;

/**
 * The code under test settled a delivery the {@see \Waffle\Commons\Testing\Queue\InMemoryQueue}
 * has not handed out, or settled it twice.
 */
final class UnknownDeliveryException extends LogicException implements QueueExceptionInterface
{
    public function __construct(string $id, ?string $receipt)
    {
        parent::__construct(sprintf(
            'Message "%s" is not in flight under receipt "%s": it was never received, or already settled.',
            $id,
            $receipt ?? '',
        ));
    }
}
