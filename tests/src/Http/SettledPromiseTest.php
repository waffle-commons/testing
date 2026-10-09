<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Http;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Waffle\Commons\Contracts\HttpClient\Enum\PromiseState;
use Waffle\Commons\Testing\Exception\SimulatedNetworkException;
use Waffle\Commons\Testing\Http\SettledPromise;
use WaffleTests\Commons\Testing\AbstractTestCase as TestCase;

#[CoversClass(SettledPromise::class)]
final class SettledPromiseTest extends TestCase
{
    public function testAFulfilledPromiseHandsItsResponseToThenAndWait(): void
    {
        $response = new Response(204);
        $promise = SettledPromise::fulfilled($response);
        $received = [];
        $failures = [];

        $returned = $promise->then(static function (ResponseInterface $answer) use (&$received): void {
            $received[] = $answer;
        })->catch(static function (Throwable $failure) use (&$failures): void {
            $failures[] = $failure;
        });

        static::assertSame($promise, $returned);
        static::assertSame(PromiseState::Fulfilled, $promise->state());
        static::assertSame([$response], $received);
        static::assertSame([], $failures);
        static::assertSame($response, $promise->wait());
    }

    public function testARejectedPromiseHandsItsFailureToCatchAndWaitThrowsIt(): void
    {
        $failure = new SimulatedNetworkException(new Request('GET', 'https://upstream.test/data'));
        $promise = SettledPromise::rejected($failure);
        $received = [];
        $failures = [];

        $returned = $promise->then(static function (ResponseInterface $answer) use (&$received): void {
            $received[] = $answer;
        })->catch(static function (Throwable $caught) use (&$failures): void {
            $failures[] = $caught;
        });

        static::assertSame($promise, $returned);
        static::assertSame(PromiseState::Rejected, $promise->state());
        static::assertSame([], $received);
        static::assertSame([$failure], $failures);

        try {
            $promise->wait();
            static::fail('Waiting on a rejected promise must throw.');
        } catch (SimulatedNetworkException $thrown) {
            static::assertSame($failure, $thrown);
        }
    }
}
