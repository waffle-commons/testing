<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Queue\Exception\QueueExceptionInterface;
use Waffle\Commons\Contracts\Queue\QueueConsumerInterface;
use Waffle\Commons\Contracts\Queue\QueueDispatcherInterface;
use Waffle\Commons\Testing\Clock\FrozenClock;
use Waffle\Commons\Testing\Exception\UnknownDeliveryException;
use Waffle\Commons\Testing\Queue\InMemoryQueue;
use WaffleTests\Commons\Testing\AbstractTestCase as TestCase;
use WaffleTests\Commons\Testing\Fixture\SendInvoice;

#[CoversClass(InMemoryQueue::class)]
#[CoversClass(UnknownDeliveryException::class)]
final class InMemoryQueueTest extends TestCase
{
    private const string NOW = '2026-10-09T09:00:00+00:00';

    public function testImplementsBothQueueContracts(): void
    {
        $queue = new InMemoryQueue(new FrozenClock(self::NOW));

        static::assertInstanceOf(QueueDispatcherInterface::class, $queue);
        static::assertInstanceOf(QueueConsumerInterface::class, $queue);
    }

    public function testRecordsWhatTheCodeUnderTestDispatched(): void
    {
        $queue = new InMemoryQueue(new FrozenClock(self::NOW));

        $first = $queue->dispatch(new SendInvoice('inv-1'));
        $second = $queue->dispatch(new SendInvoice('inv-2'), 'invoices');

        static::assertSame([$first, $second], $queue->dispatched());
        static::assertSame([$second], $queue->dispatched('invoices'));
        static::assertSame('memory-1', $first->id);
        static::assertSame('default', $first->queue);
        static::assertSame(self::NOW, $first->availableAt->format(DATE_ATOM));
        static::assertSame(0, $first->attempts);
        static::assertSame([], $first->traceContext);
    }

    public function testDeliversTheOldestAvailableMessageWithAReceipt(): void
    {
        $queue = new InMemoryQueue(new FrozenClock(self::NOW));
        $first = $queue->dispatch(new SendInvoice('inv-1'));
        $queue->dispatch(new SendInvoice('inv-2'));

        $received = $queue->receive('default');

        static::assertNotNull($received);
        static::assertSame($first->id, $received->id);
        static::assertSame('receipt-3', $received->receipt);
        static::assertNull($queue->receive('other'));
    }

    public function testADelayedMessageWaitsForTheClock(): void
    {
        $clock = new FrozenClock(self::NOW);
        $queue = new InMemoryQueue($clock);
        $queue->dispatch(new SendInvoice('inv-1'), availableAt: $clock->now()->modify('+5 minutes'));

        static::assertNull($queue->receive('default', 1000), 'The double never blocks.');

        $clock->advance('+5 minutes');
        static::assertNotNull($queue->receive('default'));
    }

    public function testAnAcknowledgedMessageIsNeverDeliveredAgain(): void
    {
        $queue = new InMemoryQueue(new FrozenClock(self::NOW));
        $queue->dispatch(new SendInvoice('inv-1'));
        $received = $queue->receive('default');
        static::assertNotNull($received);

        $queue->acknowledge($received);

        static::assertSame([$received], $queue->acknowledged());
        static::assertNull($queue->receive('default'));
    }

    public function testAReleasedMessageComesBackAsANewAttempt(): void
    {
        $clock = new FrozenClock(self::NOW);
        $queue = new InMemoryQueue($clock);
        $queue->dispatch(new SendInvoice('inv-1'));
        $received = $queue->receive('default');
        static::assertNotNull($received);

        $queue->release($received, $clock->now()->modify('+30 seconds'));

        static::assertNull($queue->receive('default'));
        $clock->advance('+30 seconds');
        $again = $queue->receive('default');
        static::assertNotNull($again);
        static::assertSame($received->id, $again->id);
        static::assertSame(1, $again->attempts);
    }

    public function testReclaimPlaysTheRecoveryAfterAWorkerCrash(): void
    {
        $queue = new InMemoryQueue(new FrozenClock(self::NOW));
        $queue->dispatch(new SendInvoice('inv-1'));
        $received = $queue->receive('default');
        static::assertNotNull($received);

        $queue->reclaim();

        $again = $queue->receive('default');
        static::assertNotNull($again);
        static::assertSame($received->id, $again->id);
        static::assertSame(1, $again->attempts);
        static::assertNotSame($received->receipt, $again->receipt);
    }

    public function testSettlingADeliveryTwiceFails(): void
    {
        $queue = new InMemoryQueue(new FrozenClock(self::NOW));
        $queue->dispatch(new SendInvoice('inv-1'));
        $received = $queue->receive('default');
        static::assertNotNull($received);
        $queue->acknowledge($received);

        try {
            $queue->acknowledge($received);
            static::fail('A settled delivery cannot be settled again.');
        } catch (UnknownDeliveryException $exception) {
            static::assertInstanceOf(QueueExceptionInterface::class, $exception);
            static::assertSame(
                'Message "memory-1" is not in flight under receipt "receipt-2": it was never received, or already settled.',
                $exception->getMessage(),
            );
        }
    }

    public function testSettlingAnEnvelopeThatWasNeverReceivedFails(): void
    {
        $queue = new InMemoryQueue(new FrozenClock(self::NOW));
        $envelope = $queue->dispatch(new SendInvoice('inv-1'));

        $this->expectException(UnknownDeliveryException::class);
        $this->expectExceptionMessage('Message "memory-1" is not in flight under receipt "": it was never received');

        $queue->release($envelope, $envelope->availableAt);
    }
}
