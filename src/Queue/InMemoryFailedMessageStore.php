<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Queue;

use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Psr\Clock\ClockInterface;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\FailedMessage;
use Waffle\Commons\Contracts\Queue\FailedMessageStoreInterface;

use function array_slice;
use function array_values;

/**
 * A dead-letter store held in memory, for tests ([TEST-01]): a test asserts what
 * a worker gave up on, with the full envelope and the reason.
 *
 * A message stored again (it failed after being dispatched once more) replaces
 * its earlier record and moves to the end. The failure instant comes from the
 * injected clock.
 */
final class InMemoryFailedMessageStore implements FailedMessageStoreInterface
{
    /**
     * @var array<string, FailedMessage> By envelope id, oldest first.
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; it records what the test asserts',
    )]
    private array $failed = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    #[\Override]
    public function store(Envelope $envelope, string $reason): void
    {
        unset($this->failed[$envelope->id]);
        $this->failed[$envelope->id] = new FailedMessage($envelope, $reason, $this->clock->now());
    }

    #[\Override]
    public function list(int $limit = 100): array
    {
        return array_slice(array_values($this->failed), 0, $limit);
    }

    #[\Override]
    public function remove(string $id): void
    {
        unset($this->failed[$id]);
    }
}
