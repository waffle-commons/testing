<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Queue;

use DateTimeImmutable;
use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Psr\Clock\ClockInterface;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\MessageInterface;
use Waffle\Commons\Contracts\Queue\QueueConsumerInterface;
use Waffle\Commons\Contracts\Queue\QueueDispatcherInterface;
use Waffle\Commons\Testing\Exception\UnknownDeliveryException;

use function array_filter;
use function array_key_exists;
use function array_values;

/**
 * A queue held in memory, for tests ([TEST-01]): it dispatches like the real
 * driver and records every message, so a test asserts what the code under test
 * queued.
 *
 * It also consumes, so a test can run a worker against it. Time comes from the
 * injected clock: a delayed message becomes available when the test advances a
 * frozen clock, and `receive()` never blocks. `reclaim()` plays the transport's
 * recovery after a worker crash.
 *
 * Ids (`memory-1`, `memory-2`, …) and receipts (`receipt-1`, …) are
 * deterministic. No trace context is recorded.
 *
 * The double is mutable on purpose and does not implement `ResettableInterface`:
 * the kernel resets after every request, and a reset would erase what the test
 * is about to assert.
 */
final class InMemoryQueue implements QueueDispatcherInterface, QueueConsumerInterface
{
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; it numbers ids and receipts',
    )]
    private int $sequence = 0;

    /**
     * @var list<Envelope>
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; it records what the test asserts',
    )]
    private array $dispatched = [];

    /**
     * The messages waiting for a delivery, by queue, oldest first.
     *
     * @var array<string, list<Envelope>>
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; it holds the queued messages',
    )]
    private array $waiting = [];

    /**
     * @var array<string, Envelope> The deliveries in flight, by receipt.
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; it holds the deliveries in flight',
    )]
    private array $inFlight = [];

    /**
     * @var list<Envelope>
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; it records what the test asserts',
    )]
    private array $acknowledged = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    #[\Override]
    public function dispatch(
        MessageInterface $message,
        string $queue = self::DEFAULT_QUEUE,
        ?DateTimeImmutable $availableAt = null,
    ): Envelope {
        $envelope = new Envelope('memory-' . ++$this->sequence, $message, $queue, $availableAt ?? $this->clock->now());
        $this->dispatched[] = $envelope;
        $this->waiting[$queue][] = $envelope;

        return $envelope;
    }

    /**
     * The oldest message of `$queue` whose `availableAt` has come, by the
     * clock; it never waits, whatever `$timeoutMs` says.
     */
    #[\Override]
    public function receive(string $queue, int $timeoutMs = 0): ?Envelope
    {
        $now = $this->clock->now();
        $waiting = $this->waiting[$queue] ?? [];
        foreach ($waiting as $index => $envelope) {
            if ($envelope->availableAt > $now) {
                continue;
            }

            unset($waiting[$index]);
            $this->waiting[$queue] = array_values($waiting);

            $receipt = 'receipt-' . ++$this->sequence;
            $received = $envelope->withReceipt($receipt);
            $this->inFlight[$receipt] = $received;

            return $received;
        }

        return null;
    }

    /**
     * @throws UnknownDeliveryException When the envelope is not a delivery in flight.
     */
    #[\Override]
    public function acknowledge(Envelope $envelope): void
    {
        $this->settle($envelope);
        $this->acknowledged[] = $envelope;
    }

    /**
     * @throws UnknownDeliveryException When the envelope is not a delivery in flight.
     */
    #[\Override]
    public function release(Envelope $envelope, DateTimeImmutable $availableAt): void
    {
        $this->settle($envelope);
        $this->waiting[$envelope->queue][] = $envelope->withNextAttempt($availableAt);
    }

    /**
     * Plays the transport's recovery after a worker crash: every delivery still
     * in flight comes back to its queue as a failed attempt, available now.
     */
    public function reclaim(): void
    {
        $now = $this->clock->now();
        foreach ($this->inFlight as $envelope) {
            $this->waiting[$envelope->queue][] = $envelope->withNextAttempt($now);
        }

        $this->inFlight = [];
    }

    /**
     * Every envelope dispatched, in order, on any queue or on `$queue` only.
     *
     * @return list<Envelope>
     */
    public function dispatched(?string $queue = null): array
    {
        if ($queue === null) {
            return $this->dispatched;
        }

        return array_values(array_filter(
            $this->dispatched,
            static fn(Envelope $envelope): bool => $envelope->queue === $queue,
        ));
    }

    /**
     * Every delivery acknowledged, in order.
     *
     * @return list<Envelope>
     */
    public function acknowledged(): array
    {
        return $this->acknowledged;
    }

    /**
     * @throws UnknownDeliveryException
     */
    private function settle(Envelope $envelope): void
    {
        $receipt = $envelope->receipt;
        if ($receipt === null || !array_key_exists($receipt, $this->inFlight)) {
            throw new UnknownDeliveryException($envelope->id, $receipt);
        }

        unset($this->inFlight[$receipt]);
    }
}
