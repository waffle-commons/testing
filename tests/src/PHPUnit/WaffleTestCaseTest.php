<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\PHPUnit;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Clock\ClockInterface;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Testing\Clock\FrozenClock;
use Waffle\Commons\Testing\Kernel\TestResponse;
use Waffle\Commons\Testing\PHPUnit\WaffleTestCase;
use WaffleTests\Commons\Testing\AbstractTestCase as TestCase;
use WaffleTests\Commons\Testing\Fixture\FixtureAppTestCase;

use function str_repeat;

#[CoversClass(WaffleTestCase::class)]
final class WaffleTestCaseTest extends TestCase
{
    public function testEveryClientGetsAFreshKernel(): void
    {
        $case = new FixtureAppTestCase('application');

        $case->client();
        $case->client();

        static::assertCount(2, $case->kernels);
        static::assertNotSame($case->kernels[0], $case->kernels[1]);
    }

    public function testTheConfigureClosureSwapsDoublesIn(): void
    {
        $case = new FixtureAppTestCase('application');
        $clock = new FrozenClock('2026-10-09T09:00:00+00:00');

        $response = $case->client(static function (ContainerInterface $container) use ($clock): void {
            $container->set(ClockInterface::class, $clock);
        })->request('GET', '/time');

        FixtureAppTestCase::assertStatus(200, $response);
        FixtureAppTestCase::assertHeader('application/json', 'content-type', $response);
        FixtureAppTestCase::assertJsonBody(['now' => '2026-10-09T09:00:00+00:00'], $response);
    }

    public function testAWrongStatusQuotesTheBody(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Expected HTTP 201, got 200. Body: hello');

        FixtureAppTestCase::assertStatus(201, self::response(200, 'hello'));
    }

    public function testAWrongStatusSaysWhenTheBodyIsEmpty(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Expected HTTP 200, got 204. Body: (empty)');

        FixtureAppTestCase::assertStatus(200, self::response(204, ''));
    }

    public function testAWrongStatusQuotesALongBodyCut(): void
    {
        try {
            FixtureAppTestCase::assertStatus(200, self::response(500, str_repeat('é', 600)));
            static::fail('A wrong status must fail.');
        } catch (ExpectationFailedException $exception) {
            static::assertStringContainsString('Body: ' . str_repeat('é', 500) . '…', $exception->getMessage());
            static::assertStringNotContainsString(str_repeat('é', 501), $exception->getMessage());
        }
    }

    public function testAWrongStatusUsesTheTestsMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('the order must be created');

        FixtureAppTestCase::assertStatus(201, self::response(200, 'hello'), 'the order must be created');
    }

    public function testAnAbsentHeaderFails(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The response has no "X-Missing" header.');

        FixtureAppTestCase::assertHeader('yes', 'X-Missing', self::response(200, ''));
    }

    public function testAWrongHeaderValueFails(): void
    {
        $this->expectException(ExpectationFailedException::class);

        FixtureAppTestCase::assertHeader('no', 'x-fixture', self::response(200, '', ['x-fixture' => 'yes']));
    }

    public function testAJsonBodyThatDiffersFails(): void
    {
        $this->expectException(ExpectationFailedException::class);

        FixtureAppTestCase::assertJsonBody(['id' => 2], self::response(200, '{"id":1}'));
    }

    public function testABodyThatIsNotJsonFailsQuotingIt(): void
    {
        try {
            FixtureAppTestCase::assertJsonBody(['id' => 1], self::response(200, 'plain text'));
            static::fail('A body that is not JSON must fail.');
        } catch (AssertionFailedError $failure) {
            static::assertNotInstanceOf(ExpectationFailedException::class, $failure);
            static::assertStringStartsWith('The response body is not a JSON object or list: ', $failure->getMessage());
            static::assertStringEndsWith('Body: plain text', $failure->getMessage());
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function response(int $status, string $body, array $headers = []): TestResponse
    {
        return new TestResponse(new Response($status, $headers, $body), $body);
    }
}
