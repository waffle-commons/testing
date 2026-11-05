<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\RateLimit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\RateLimit\TokenBucket;
use Waffle\Commons\Testing\Clock\FrozenClock;
use Waffle\Commons\Testing\RateLimit\InMemoryLimiterStateStorage;
use WaffleTests\Commons\Testing\AbstractTestCase;

#[CoversClass(InMemoryLimiterStateStorage::class)]
final class InMemoryLimiterStateStorageTest extends AbstractTestCase
{
    public function testAFreshBucketStartsFullAndCountsDown(): void
    {
        $storage = new InMemoryLimiterStateStorage(new FrozenClock('2026-10-31T08:00:00+00:00'));
        $bucket = new TokenBucket(3, 60);

        $first = $storage->consume('ip:203.0.113.7', $bucket, 1);

        static::assertTrue($first->allowed);
        static::assertSame(3, $first->limit);
        static::assertSame(2, $first->remaining);
        static::assertSame(20, $first->resetSeconds);
        static::assertSame(0, $first->retryAfterSeconds);
        static::assertTrue($first->enforced);
        static::assertSame(1, $storage->consume('ip:203.0.113.7', $bucket, 1)->remaining);
        static::assertSame(0, $storage->consume('ip:203.0.113.7', $bucket, 1)->remaining);
    }

    public function testAnEmptyBucketDeniesWithARetryAfter(): void
    {
        $storage = new InMemoryLimiterStateStorage(new FrozenClock('2026-10-31T08:00:00+00:00'));
        $bucket = new TokenBucket(2, 60);
        $storage->consume('login:alice', $bucket, 1);
        $storage->consume('login:alice', $bucket, 1);

        $denied = $storage->consume('login:alice', $bucket, 1);

        static::assertFalse($denied->allowed);
        static::assertSame(0, $denied->remaining);
        static::assertSame(60, $denied->resetSeconds);
        static::assertSame(30, $denied->retryAfterSeconds);
    }

    public function testTheBucketRefillsAsTheClockAdvancesUpToItsCapacity(): void
    {
        $clock = new FrozenClock('2026-10-31T08:00:00+00:00');
        $storage = new InMemoryLimiterStateStorage($clock);
        $bucket = new TokenBucket(2, 60);
        $storage->consume('k', $bucket, 2);

        $clock->advance('+30 seconds');
        $refilled = $storage->consume('k', $bucket, 1);
        $clock->advance('+1 day');
        $full = $storage->consume('k', $bucket, 1);

        static::assertTrue($refilled->allowed);
        static::assertSame(0, $refilled->remaining);
        static::assertTrue($full->allowed);
        static::assertSame(1, $full->remaining);
    }

    public function testEachKeyHasItsOwnBucket(): void
    {
        $storage = new InMemoryLimiterStateStorage(new FrozenClock('2026-10-31T08:00:00+00:00'));
        $bucket = new TokenBucket(1, 60);

        static::assertTrue($storage->consume('a', $bucket, 1)->allowed);
        static::assertFalse($storage->consume('a', $bucket, 1)->allowed);
        static::assertTrue($storage->consume('b', $bucket, 1)->allowed);
    }

    public function testAClockSetBackNeverAddsTokens(): void
    {
        $clock = new FrozenClock('2026-10-31T08:00:00+00:00');
        $storage = new InMemoryLimiterStateStorage($clock);
        $bucket = new TokenBucket(1, 60);
        $storage->consume('k', $bucket, 1);

        $clock->set('2026-10-31T07:00:00+00:00');

        static::assertFalse($storage->consume('k', $bucket, 1)->allowed);
    }

    public function testAnEmptyKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InMemoryLimiterStateStorage(new FrozenClock('2026-10-31T08:00:00+00:00'))->consume(
            '',
            new TokenBucket(1, 60),
            1,
        );
    }

    public function testACostTheBucketCannotHoldIsRefused(): void
    {
        $storage = new InMemoryLimiterStateStorage(new FrozenClock('2026-10-31T08:00:00+00:00'));

        $this->expectException(InvalidArgumentException::class);

        $storage->consume('k', new TokenBucket(2, 60), 3);
    }

    public function testANonPositiveCostIsRefused(): void
    {
        $storage = new InMemoryLimiterStateStorage(new FrozenClock('2026-10-31T08:00:00+00:00'));

        $this->expectException(InvalidArgumentException::class);

        $storage->consume('k', new TokenBucket(2, 60), 0);
    }
}
