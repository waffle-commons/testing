<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Kernel;

use Closure;
use InvalidArgumentException;
use JsonException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\Core\TerminableInterface;
use Waffle\Commons\Testing\Exception\MissingServiceException;

use function array_key_exists;
use function explode;
use function is_array;
use function json_decode;
use function json_encode;
use function mb_trim;
use function parse_str;
use function parse_url;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function urldecode;

/**
 * Sends requests through an application kernel in-process, as the FrankenPHP
 * worker does, with no web server and no network ([TEST-01]).
 *
 * `boot()` runs the kernel's boot sequence and gives the test one chance to
 * swap test doubles into the container, between `boot()` and `configure()`,
 * before the container locks ([CARRY-04]). A double reaches what the container
 * resolves (controllers and their dependencies), not a collaborator the
 * application's kernel factory built up front.
 *
 * Every request runs the worker's sequence: `handle()`, then `terminate()` when
 * the kernel is terminable, then `reset()` — even when the kernel throws. Sending
 * the same request twice therefore shows whether any state leaks from one
 * request into the next.
 *
 * Requests are built with the PSR-17 factories the kernel's container provides,
 * and carry what the production `GlobalsFactory` derives from a real request:
 * CGI server parameters, query parameters, cookies, and the parsed body. Like
 * production, a body is parsed only for `POST`: a urlencoded form into an array,
 * a JSON body into an array (`null` when it is empty, malformed or a scalar).
 * Any other method leaves the parsed body `null`, as it is in production today.
 */
final readonly class KernelClient
{
    public const string DEFAULT_HOST = 'localhost';

    public const string DEFAULT_REMOTE_ADDR = '127.0.0.1';

    private function __construct(
        private KernelInterface $kernel,
        private ServerRequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Boots the kernel, lets `$configure` swap test doubles into its container,
     * then configures the kernel, which locks the container.
     *
     * @param null|Closure(ContainerInterface): void $configure
     *
     * @throws MissingServiceException When the container provides no PSR-17 server-request or stream factory.
     */
    public static function boot(KernelInterface $kernel, ?Closure $configure = null): self
    {
        $kernel->boot();
        if ($configure !== null) {
            $configure($kernel->container);
        }

        $kernel->configure();

        return new self(
            $kernel,
            self::service($kernel->container, ServerRequestFactoryInterface::class),
            self::service($kernel->container, StreamFactoryInterface::class),
        );
    }

    /**
     * The kernel's container, to read a service after a request (a repository, a
     * double the kernel resolved). Its registrations are locked.
     */
    public function container(): ContainerInterface
    {
        return $this->kernel->container;
    }

    /**
     * Sends a request and returns the response.
     *
     * @param string                $uri     A path (`/users/42?expand=1`, served as `http://localhost`) or an absolute URI.
     * @param array<string, string> $headers
     * @param array<string, string> $server  Server parameters that replace the defaults, e.g. `REMOTE_ADDR`.
     *
     * @throws InvalidArgumentException When `$uri` is neither a path nor an absolute URI, or the body is multipart.
     */
    public function request(
        string $method,
        string $uri,
        array $headers = [],
        string $body = '',
        array $server = [],
    ): TestResponse {
        return $this->send($this->createRequest($method, $uri, $headers, $body, $server));
    }

    /**
     * Sends `$data` as a JSON body, with `Content-Type` and `Accept` set to
     * `application/json` unless `$headers` says otherwise.
     *
     * @param array<string, string> $headers
     *
     * @throws JsonException            When `$data` holds a value JSON cannot encode.
     * @throws InvalidArgumentException When `$uri` is neither a path nor an absolute URI.
     */
    public function jsonRequest(string $method, string $uri, array $data, array $headers = []): TestResponse
    {
        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $this->request(
            $method,
            $uri,
            ['content-type' => 'application/json', 'accept' => 'application/json', ...$headers],
            $json,
        );
    }

    /**
     * Sends a prepared request through the worker's sequence: handle, terminate,
     * reset. The body is read before the kernel terminates, as the worker emits it.
     */
    public function send(ServerRequestInterface $request): TestResponse
    {
        try {
            $response = $this->kernel->handle($request);
            $received = new TestResponse($response, (string) $response->getBody());

            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($request, $response);
            }

            return $received;
        } finally {
            $this->kernel->reset();
        }
    }

    /**
     * Builds the request a worker would receive, without sending it.
     *
     * @param string                $uri     A path (served as `http://localhost`) or an absolute URI.
     * @param array<string, string> $headers
     * @param array<string, string> $server  Server parameters that replace the defaults, e.g. `REMOTE_ADDR`.
     *
     * @throws InvalidArgumentException When `$uri` is neither a path nor an absolute URI, or the body is multipart.
     */
    public function createRequest(
        string $method,
        string $uri,
        array $headers = [],
        string $body = '',
        array $server = [],
    ): ServerRequestInterface {
        $method = strtoupper($method);
        $target = str_starts_with($uri, '/') ? 'http://' . self::DEFAULT_HOST . $uri : $uri;
        $parts = parse_url($target);
        if ($parts === false || !array_key_exists('host', $parts)) {
            throw new InvalidArgumentException(
                'The request URI must be a path or an absolute URI with a host, got "' . $uri . '".',
            );
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';
        $host = $port === null ? $parts['host'] : $parts['host'] . ':' . $port;

        $serverParams = [
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $query === '' ? $path : $path . '?' . $query,
            'QUERY_STRING' => $query,
            'SERVER_NAME' => $parts['host'],
            'SERVER_PORT' => (string) ($port ?? ($scheme === 'https' ? 443 : 80)),
            'REMOTE_ADDR' => self::DEFAULT_REMOTE_ADDR,
            'HTTP_HOST' => $host,
        ];
        if ($scheme === 'https') {
            $serverParams['HTTPS'] = 'on';
        }

        foreach ($headers as $name => $value) {
            $serverParams[self::cgiName($name)] = $value;
        }

        if ($body !== '') {
            $serverParams['CONTENT_LENGTH'] = (string) strlen($body);
        }

        $request = $this->requestFactory->createServerRequest(
            $method,
            $target,
            [...$serverParams, ...$server],
        )->withHeader('host', $host);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $queryParams = [];
        parse_str($query, $queryParams);
        $request = $request
            ->withQueryParams($queryParams)
            ->withCookieParams(self::cookies($request->getHeaderLine('cookie')));

        if ($body !== '') {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        return $request->withParsedBody(self::parsedBody($method, $request->getHeaderLine('content-type'), $body));
    }

    /**
     * The CGI name of a request header: `Content-Type` is `CONTENT_TYPE`, any
     * other header `HTTP_` plus its name (`X-Api-Key` is `HTTP_X_API_KEY`).
     */
    private static function cgiName(string $header): string
    {
        $name = strtoupper(str_replace('-', '_', $header));

        return $name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH' ? $name : 'HTTP_' . $name;
    }

    /**
     * Parses a `Cookie` header (`a=1; b=two%20words`) into the cookie
     * parameters: values URL-decoded, the first cookie of a name kept.
     *
     * @return array<string, string>
     */
    private static function cookies(string $header): array
    {
        $cookies = [];
        foreach (explode(';', $header) as $pair) {
            $separator = strpos($pair, '=');
            if ($separator === false) {
                continue;
            }

            $name = mb_trim(substr($pair, 0, $separator));
            if ($name === '' || array_key_exists($name, $cookies)) {
                continue;
            }

            $cookies[$name] = urldecode(substr($pair, $separator + 1));
        }

        return $cookies;
    }

    /**
     * The parsed body as the production `GlobalsFactory` derives it.
     *
     * @throws InvalidArgumentException When the body is `multipart/form-data`, which the bridge does not build.
     */
    private static function parsedBody(string $method, string $contentType, string $body): ?array
    {
        if ($method !== 'POST') {
            return null;
        }

        $mime = mb_trim(strtolower(explode(';', $contentType)[0]));
        if ($mime === 'application/x-www-form-urlencoded') {
            $form = [];
            parse_str($body, $form);

            return $form;
        }

        if ($mime === 'multipart/form-data') {
            throw new InvalidArgumentException('The testing bridge does not build multipart/form-data bodies.');
        }

        if ($mime !== 'application/json' || $body === '') {
            return null;
        }

        try {
            // json_decode() returns its result untyped: it is kept in a one-element
            // list and narrowed in place, never bound on its own.
            $decoded = [json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded[0]) ? $decoded[0] : null;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     *
     * @throws MissingServiceException When the container has no `$id`, or what it returns is not one.
     */
    private static function service(ContainerInterface $container, string $id): object
    {
        if (!$container->has($id)) {
            throw new MissingServiceException($id);
        }

        try {
            // The container returns its entries untyped: the entry is kept in a
            // one-element list and narrowed in place, never bound on its own.
            $service = [$container->get($id)];
        } catch (ContainerExceptionInterface $exception) {
            throw new MissingServiceException($id, $exception);
        }

        if (!$service[0] instanceof $id) {
            throw new MissingServiceException($id);
        }

        return $service[0];
    }
}
