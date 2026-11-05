<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\PHPUnit;

use Closure;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Testing\Exception\InvalidJsonException;
use Waffle\Commons\Testing\Exception\MissingServiceException;
use Waffle\Commons\Testing\Kernel\KernelClient;
use Waffle\Commons\Testing\Kernel\TestResponse;

use function mb_strlen;
use function mb_substr;
use function sprintf;

/**
 * PHPUnit base class for tests that send requests through the application's
 * real pipeline, in-process ([TEST-01]).
 *
 * The application implements `createKernel()` in one line, e.g.
 * `return AppKernelFactory::create(Constant::ENV_TEST);`. Each `createClient()`
 * builds and boots a fresh kernel, so no state crosses from one test to the
 * next; within a test, the client resets the kernel after every request, as the
 * worker does.
 *
 * ```php
 * $clock = new FrozenClock('2026-10-09T09:00:00+00:00');
 * $client = $this->createClient(static function (ContainerInterface $container) use ($clock): void {
 *     $container->set(ClockInterface::class, $clock);
 * });
 *
 * $response = $client->request('GET', '/orders/42');
 *
 * self::assertStatus(200, $response);
 * self::assertJsonBody(['id' => 42, 'status' => 'shipped'], $response);
 * ```
 *
 * This namespace is the only place in the component that depends on PHPUnit: the
 * kernel client and the test doubles work with any test runner.
 */
abstract class WaffleTestCase extends TestCase
{
    /**
     * How much of a body a failed assertion quotes.
     */
    private const int BODY_EXCERPT = 500;

    /**
     * Builds the application's kernel. Called once per `createClient()`.
     */
    abstract protected function createKernel(): KernelInterface;

    /**
     * Boots a fresh kernel and returns a client for it.
     *
     * @param null|Closure(ContainerInterface): void $configure Swaps test doubles into the container before it locks.
     *
     * @throws MissingServiceException When the container provides no PSR-17 server-request or stream factory.
     */
    protected function createClient(?Closure $configure = null): KernelClient
    {
        return KernelClient::boot($this->createKernel(), $configure);
    }

    /**
     * Asserts the response status, quoting the body when it differs.
     *
     * @throws ExpectationFailedException When the status differs.
     */
    public static function assertStatus(int $expected, TestResponse $response, string $message = ''): void
    {
        static::assertSame(
            $expected,
            $response->status(),
            $message !== ''
                ? $message
                : sprintf(
                    'Expected HTTP %d, got %d. Body: %s',
                    $expected,
                    $response->status(),
                    self::excerpt($response->body),
                ),
        );
    }

    /**
     * Asserts a response header's value (its values joined by commas).
     *
     * @throws ExpectationFailedException When the header is absent or its value differs.
     */
    public static function assertHeader(
        string $expected,
        string $name,
        TestResponse $response,
        string $message = '',
    ): void {
        static::assertTrue(
            $response->hasHeader($name),
            $message !== '' ? $message : sprintf('The response has no "%s" header.', $name),
        );
        static::assertSame($expected, $response->header($name), $message);
    }

    /**
     * Asserts the body decodes to exactly `$expected`: same keys, same order,
     * same types.
     *
     * @throws AssertionFailedError       When the body is not a JSON object or list.
     * @throws ExpectationFailedException When the decoded body differs.
     */
    public static function assertJsonBody(array $expected, TestResponse $response, string $message = ''): void
    {
        try {
            $actual = $response->json();
        } catch (InvalidJsonException $exception) {
            static::fail(sprintf('%s Body: %s', $exception->getMessage(), self::excerpt($response->body)));
        }

        static::assertSame($expected, $actual, $message);
    }

    private static function excerpt(string $body): string
    {
        if ($body === '') {
            return '(empty)';
        }

        return mb_strlen($body) > self::BODY_EXCERPT ? mb_substr($body, 0, self::BODY_EXCERPT) . '…' : $body;
    }
}
