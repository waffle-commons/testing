<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Kernel;

use JsonException;
use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Testing\Exception\InvalidJsonException;

use function is_array;
use function json_decode;

/**
 * The response a {@see KernelClient} received, with its body read once.
 *
 * The body is captured as the worker emits it, before the kernel terminates and
 * resets, so assertions never depend on a stream's position.
 */
final readonly class TestResponse
{
    public function __construct(
        public ResponseInterface $response,
        public string $body,
    ) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * The header's values joined by commas, or `''` when it is absent.
     */
    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    /**
     * Decodes the body as a JSON object or list.
     *
     * @throws InvalidJsonException When the body is not JSON, or decodes to a scalar or null.
     */
    public function json(): array
    {
        try {
            // json_decode() returns its result untyped: it is kept in a one-element
            // list and narrowed in place, never bound on its own.
            $decoded = [json_decode($this->body, true, 512, JSON_THROW_ON_ERROR)];
        } catch (JsonException $exception) {
            throw new InvalidJsonException($exception->getMessage(), $exception);
        }

        if (!is_array($decoded[0])) {
            throw new InvalidJsonException('it decodes to a scalar or null.');
        }

        return $decoded[0];
    }
}
