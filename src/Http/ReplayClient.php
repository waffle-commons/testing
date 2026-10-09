<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Http;

use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\HttpClient\PromiseInterface;
use Waffle\Commons\Testing\Exception\SimulatedNetworkException;
use Waffle\Commons\Testing\Exception\UnexpectedRequestException;

use function array_slice;
use function count;
use function strtoupper;

/**
 * HTTP client double that replays canned answers and records every request
 * ([TEST-01]). No test ever reaches the network.
 *
 * A test queues the answers it expects, each for one method and one URI:
 * a response (`addResponse()`) or a network failure (`addNetworkError()`).
 * Every request takes the next answer queued for its method and URI, and each
 * answer is served once. A request with no answer left throws an
 * {@see UnexpectedRequestException}, so an unplanned call (an extra retry, a
 * wrong URL) fails the test instead of passing silently. The URI is matched
 * exactly, as PSR-7 prints the request's URI.
 *
 * It implements both client contracts the application can depend on: PSR-18
 * `ClientInterface` and the concurrent `ConcurrentClientInterface`, whose
 * promises it hands back already settled.
 *
 * The double is mutable on purpose and does not implement `ResettableInterface`:
 * the kernel resets after every request, and a reset would erase what the test
 * is about to assert.
 */
final class ReplayClient implements ClientInterface, ConcurrentClientInterface
{
    /**
     * The answers still to serve, by method and URI; `null` is a network failure.
     *
     * @var array<string, non-empty-list<null|ResponseInterface>>
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; the test queues its answers',
    )]
    private array $answers = [];

    /**
     * @var list<RequestInterface>
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; it records what the test asserts',
    )]
    private array $requests = [];

    /**
     * Queues a response for the next `$method` request to `$uri`.
     */
    public function addResponse(string $method, string $uri, ResponseInterface $response): void
    {
        $this->answers[self::key($method, $uri)][] = $response;
    }

    /**
     * Queues a network failure for the next `$method` request to `$uri`: the
     * request throws a {@see SimulatedNetworkException}, a PSR-18 network
     * exception.
     */
    public function addNetworkError(string $method, string $uri): void
    {
        $this->answers[self::key($method, $uri)][] = null;
    }

    /**
     * Every request received, answered or not, in the order it arrived.
     *
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * How many queued answers no request has taken yet.
     */
    public function pending(): int
    {
        $pending = 0;
        foreach ($this->answers as $answers) {
            $pending += count($answers);
        }

        return $pending;
    }

    /**
     * @throws SimulatedNetworkException  When the answer queued for the request is a network failure.
     * @throws UnexpectedRequestException When no answer is left for the request's method and URI.
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $method = strtoupper($request->getMethod());
        $uri = (string) $request->getUri();
        $key = self::key($method, $uri);

        $answers = $this->answers[$key] ?? [];
        if ($answers === []) {
            throw new UnexpectedRequestException($method, $uri);
        }

        $remaining = array_slice($answers, 1);
        if ($remaining === []) {
            unset($this->answers[$key]);
        } else {
            $this->answers[$key] = $remaining;
        }

        return $answers[0] ?? throw new SimulatedNetworkException($request);
    }

    /**
     * Answers each request in turn, keeping its key.
     *
     * @param array<array-key, RequestInterface> $requests
     *
     * @return array<array-key, ResponseInterface>
     *
     * @throws SimulatedNetworkException  When an answer is a network failure.
     * @throws UnexpectedRequestException When a request has no answer left.
     */
    #[\Override]
    public function sendRequests(array $requests): array
    {
        $responses = [];
        foreach ($requests as $key => $request) {
            $responses[$key] = $this->sendRequest($request);
        }

        return $responses;
    }

    /**
     * Answers the request at once: a queued network failure rejects the promise.
     *
     * @throws UnexpectedRequestException When no answer is left for the request.
     */
    #[\Override]
    public function promise(RequestInterface $request): PromiseInterface
    {
        try {
            return SettledPromise::fulfilled($this->sendRequest($request));
        } catch (SimulatedNetworkException $failure) {
            return SettledPromise::rejected($failure);
        }
    }

    private static function key(string $method, string $uri): string
    {
        return strtoupper($method) . ' ' . $uri;
    }
}
