<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Exception;

use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Waffle\Commons\Contracts\HttpClient\Exception\NetworkExceptionInterface;

use function sprintf;

/**
 * A network failure the {@see \Waffle\Commons\Testing\Http\ReplayClient} raises
 * on purpose, so a test can exercise retries, fallbacks and circuit breakers
 * without a real outage.
 */
final class SimulatedNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
    ) {
        parent::__construct(sprintf(
            'Simulated network failure for %s %s.',
            $request->getMethod(),
            (string) $request->getUri(),
        ));
    }

    #[\Override]
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
