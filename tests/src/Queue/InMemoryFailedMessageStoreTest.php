<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Queue;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Testing\Clock\FrozenClock;
use Waffle\Commons\Testing\Queue\InMemoryFailedMessageStore;
use WaffleTests\Commons\Testing\AbstractTestCase as TestCase;
use WaffleTests\Commons\Testing\Fixture\SendInvoice;

#[CoversClass(InMemoryFailedMessageStore::class)]
final class InMemoryFailedMessageStoreTest extends TestCase
{
    public function testKeepsTheFullEnvelopeTheReasonAndTheInstant(): void
    {
        $clock = new FrozenClock('2026-10-09T09:10:00+00:00');
        $store = new InMemoryFailedMessageStore($clock);
        $envelope = self::envelope('msg-1');

        $store->store($envelope, 'RuntimeException: the PDF renderer timed out');

        $failed = $store->list();
        static::assertCount(1, $failed);
        $first = $failed[0] ?? null;
        static::assertNotNull($first);
        static::assertSame($envelope, $first->envelope);
        static::assertSame('RuntimeException: the PDF renderer timed out', $first->reason);
        static::assertSame('2026-10-09T09:10:00+00:00', $first->failedAt->format(DATE_ATOM));
    }

    public function testListsTheOldestFirstAndHonoursTheLimit(): void
    {
        $store = new InMemoryFailedMessageStore(new FrozenClock('2026-10-09T09:10:00+00:00'));
        $store->store(self::envelope('msg-1'), 'first');
        $store->store(self::envelope('msg-2'), 'second');
        $store->store(self::envelope('msg-1'), 'failed again');

        static::assertSame(
            ['second', 'failed again'],
            array_map(static fn($failed): string => $failed->reason, $store->list()),
        );
        static::assertCount(1, $store->list(1));
    }

    public function testForgetsARemovedMessage(): void
    {
        $store = new InMemoryFailedMessageStore(new FrozenClock('2026-10-09T09:10:00+00:00'));
        $store->store(self::envelope('msg-1'), 'first');

        $store->remove('msg-1');
        $store->remove('never stored');

        static::assertSame([], $store->list());
    }

    private static function envelope(string $id): Envelope
    {
        return new Envelope(
            $id,
            new SendInvoice('inv-1'),
            'default',
            new DateTimeImmutable('2026-10-09T09:00:00+00:00'),
            3,
        );
    }
}
