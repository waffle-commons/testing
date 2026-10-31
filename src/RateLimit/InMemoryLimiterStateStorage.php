<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\RateLimit;

use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Waffle\Commons\Contracts\RateLimit\LimiterStateStorageInterface;
use Waffle\Commons\Contracts\RateLimit\RateLimitDecision;
use Waffle\Commons\Contracts\RateLimit\TokenBucket;

use function ceil;
use function floor;
use function max;
use function min;

/**
 * Token buckets held in memory, for tests ([NET-01], [TEST-01]): the arithmetic
 * of the Redis store in `waffle-commons/data`, on the injected clock, so a test
 * refills a bucket by advancing a frozen clock instead of waiting.
 *
 * Correct for one process only, which is why it lives here and not in a
 * production component: two workers would each hold their own buckets and
 * together admit more than any bucket holds. Production keeps limiter state in
 * Redis, never in worker memory (RFC-024 §5.1).
 *
 * The double is mutable on purpose and does not implement `ResettableInterface`:
 * the kernel resets after every request, and a reset would refill every bucket
 * between two requests of the same test.
 */
final class InMemoryLimiterStateStorage implements LimiterStateStorageInterface
{
    /**
     * @var array<string, array{tokens: float, at: float}> Each bucket's tokens and the instant they were counted.
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; it holds the buckets the test drains',
    )]
    private array $buckets = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    #[\Override]
    public function consume(string $key, TokenBucket $bucket, int $cost): RateLimitDecision
    {
        if ($key === '') {
            throw new InvalidArgumentException('A rate-limit key must not be empty.');
        }

        if ($cost < 1 || $cost > $bucket->capacity) {
            throw new InvalidArgumentException('A rate-limit cost must be between 1 and the bucket capacity.');
        }

        $instant = $this->clock->now();
        $now = $instant->getTimestamp() + ($instant->getMicrosecond() / 1_000_000);
        $rate = $bucket->capacity / $bucket->periodSeconds;
        $state = $this->buckets[$key] ?? ['tokens' => (float) $bucket->capacity, 'at' => $now];
        $tokens = min((float) $bucket->capacity, $state['tokens'] + (max(0.0, $now - $state['at']) * $rate));
        $allowed = $tokens >= $cost;
        if ($allowed) {
            $tokens -= $cost;
        }

        $this->buckets[$key] = ['tokens' => $tokens, 'at' => $now];

        return new RateLimitDecision(
            $allowed,
            $bucket->capacity,
            (int) floor($tokens),
            (int) ceil(($bucket->capacity - $tokens) / $rate),
            $allowed ? 0 : max(1, (int) ceil(($cost - $tokens) / $rate)),
        );
    }
}
