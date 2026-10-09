<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\HttpClient\Enum\PromiseState;
use Waffle\Commons\Contracts\HttpClient\PromiseInterface;

/**
 * A promise that is already settled when it is created: the
 * {@see ReplayClient} answers synchronously, so its concurrent path hands back
 * fulfilled or rejected promises.
 *
 * Callbacks registered with `then()` or `catch()` run immediately when they
 * match the outcome, as the contract requires of a settled promise.
 */
final readonly class SettledPromise implements PromiseInterface
{
    private function __construct(
        private ResponseInterface|ClientExceptionInterface $outcome,
    ) {}

    public static function fulfilled(ResponseInterface $response): self
    {
        return new self($response);
    }

    public static function rejected(ClientExceptionInterface $failure): self
    {
        return new self($failure);
    }

    #[\Override]
    public function state(): PromiseState
    {
        return $this->outcome instanceof ResponseInterface ? PromiseState::Fulfilled : PromiseState::Rejected;
    }

    #[\Override]
    public function then(callable $onFulfilled): self
    {
        if ($this->outcome instanceof ResponseInterface) {
            $onFulfilled($this->outcome);
        }

        return $this;
    }

    #[\Override]
    public function catch(callable $onRejected): self
    {
        if (!$this->outcome instanceof ResponseInterface) {
            $onRejected($this->outcome);
        }

        return $this;
    }

    /**
     * @throws ClientExceptionInterface When the promise was rejected.
     */
    #[\Override]
    public function wait(): ResponseInterface
    {
        if ($this->outcome instanceof ResponseInterface) {
            return $this->outcome;
        }

        throw $this->outcome;
    }
}
