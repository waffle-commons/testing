<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Fixture;

use Waffle\Commons\Contracts\Service\ResettableInterface;

/**
 * Request-scoped state that the kernel's reset clears: if the client skipped the
 * reset, a second request would see the first one's visit.
 */
final class VisitCounter implements ResettableInterface
{
    public int $visits = 0;

    public function visit(): int
    {
        return ++$this->visits;
    }

    #[\Override]
    public function reset(): void
    {
        $this->visits = 0;
    }
}
