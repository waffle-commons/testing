<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Clock;

use DateInterval;
use DateTimeImmutable;
use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock that stands still until the test moves it ([TEST-01]).
 *
 * Swap it in for the application's `ClockInterface` and every reading of the
 * time becomes deterministic: `now()` returns the same instant until `set()` or
 * `advance()` moves it. Time-dependent behaviour (expiry, rate windows, retry
 * delays) is then tested without sleeping.
 *
 * The clock is mutable on purpose and does not implement `ResettableInterface`:
 * the kernel resets after every request, and a reset would undo the time a test
 * has just set.
 */
final class FrozenClock implements ClockInterface
{
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; the test moves the time on purpose',
    )]
    private DateTimeImmutable $now;

    /**
     * @param DateTimeImmutable|string $now The instant the clock shows, e.g. `'2026-10-09T09:00:00+00:00'`.
     *
     * @throws \DateMalformedStringException When `$now` is a string PHP cannot parse.
     */
    public function __construct(DateTimeImmutable|string $now)
    {
        $this->now = self::instant($now);
    }

    #[\Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Moves the clock to another instant, earlier or later.
     *
     * @throws \DateMalformedStringException When `$now` is a string PHP cannot parse.
     */
    public function set(DateTimeImmutable|string $now): void
    {
        $this->now = self::instant($now);
    }

    /**
     * Moves the clock by an interval, or by a relative format such as
     * `'+90 seconds'` or `'-1 day'`.
     *
     * @throws \DateMalformedStringException When `$by` is a string PHP cannot parse.
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'DateTimeImmutable::add()/modify() return a new instance, nothing is written through it (igor 0.9.x write-through false positive, CARRY-05); the clock is a per-test double',
    )]
    public function advance(DateInterval|string $by): void
    {
        $this->now = $by instanceof DateInterval ? $this->now->add($by) : $this->now->modify($by);
    }

    /**
     * @throws \DateMalformedStringException
     */
    private static function instant(DateTimeImmutable|string $now): DateTimeImmutable
    {
        return $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable($now);
    }
}
