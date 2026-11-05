<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Clock;

use DateInterval;
use DateMalformedStringException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Waffle\Commons\Testing\Clock\FrozenClock;
use WaffleTests\Commons\Testing\AbstractTestCase as TestCase;

#[CoversClass(FrozenClock::class)]
final class FrozenClockTest extends TestCase
{
    public function testIsAPsr20Clock(): void
    {
        static::assertInstanceOf(ClockInterface::class, new FrozenClock('2026-10-09T09:00:00+00:00'));
    }

    public function testShowsTheInstantItWasGiven(): void
    {
        $instant = new DateTimeImmutable('2026-10-09T09:00:00+00:00');

        static::assertSame($instant, new FrozenClock($instant)->now());
        static::assertSame(
            '2026-10-09T09:00:00+02:00',
            new FrozenClock('2026-10-09T09:00:00+02:00')->now()->format(DATE_ATOM),
        );
    }

    public function testStandsStillBetweenReadings(): void
    {
        $clock = new FrozenClock('2026-10-09T09:00:00+00:00');

        static::assertSame($clock->now(), $clock->now());
    }

    public function testSetMovesTheClockToAnyInstant(): void
    {
        $clock = new FrozenClock('2026-10-09T09:00:00+00:00');

        $clock->set('2026-01-01T00:00:00+00:00');
        static::assertSame('2026-01-01T00:00:00+00:00', $clock->now()->format(DATE_ATOM));

        $later = new DateTimeImmutable('2027-01-01T00:00:00+00:00');
        $clock->set($later);
        static::assertSame($later, $clock->now());
    }

    public function testAdvanceMovesByARelativeFormat(): void
    {
        $clock = new FrozenClock('2026-10-09T09:00:00+00:00');

        $clock->advance('+90 seconds');
        static::assertSame('2026-10-09T09:01:30+00:00', $clock->now()->format(DATE_ATOM));

        $clock->advance('-1 day');
        static::assertSame('2026-10-08T09:01:30+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testAdvanceMovesByAnInterval(): void
    {
        $clock = new FrozenClock('2026-10-09T09:00:00+00:00');

        $clock->advance(new DateInterval('PT1H'));

        static::assertSame('2026-10-09T10:00:00+00:00', $clock->now()->format(DATE_ATOM));
    }

    public function testRejectsAnInstantPhpCannotParse(): void
    {
        $this->expectException(DateMalformedStringException::class);

        new FrozenClock('not a date');
    }

    public function testRejectsAMoveByAFormatPhpCannotParse(): void
    {
        $clock = new FrozenClock('2026-10-09T09:00:00+00:00');

        try {
            $clock->advance('sometime soon');
            static::fail('An unparsable move must throw.');
        } catch (DateMalformedStringException) {
            static::assertSame('2026-10-09T09:00:00+00:00', $clock->now()->format(DATE_ATOM));
        }
    }
}
