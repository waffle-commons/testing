<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Fixture;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Contracts\Core\TerminableInterface;
use Waffle\Commons\Testing\Clock\FrozenClock;

/**
 * A terminable kernel that records its lifecycle and answers a few routes:
 *
 * - `/hello`: a plain body and a header;
 * - `/time`: the instant the container's clock shows;
 * - `/visits`: a request-scoped counter, cleared by `reset()`;
 * - `/echo`: what the request carried (method, headers, query, cookies, parsed body, server parameters);
 * - `/upstream`: the answer of the container's HTTP client for `GET https://upstream.test/data`;
 * - `/boom`: throws.
 *
 * `configure()` registers its services only where the test has not swapped a
 * double in, then locks the container.
 */
final class FixtureKernel implements KernelInterface, TerminableInterface
{
    public const string DEFAULT_TIME = '2000-01-01T00:00:00+00:00';

    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        public readonly FixtureContainer $container = new FixtureContainer(),
        private readonly bool $registerFactories = true,
    ) {}

    #[\Override]
    public function boot(): static
    {
        $this->calls[] = 'boot';

        return $this;
    }

    #[\Override]
    public function configure(): void
    {
        $this->calls[] = 'configure';

        if ($this->registerFactories) {
            $factory = new Psr17Factory();
            $this->registerDefault(ServerRequestFactoryInterface::class, $factory);
            $this->registerDefault(StreamFactoryInterface::class, $factory);
        }

        $this->registerDefault(ClockInterface::class, new FrozenClock(self::DEFAULT_TIME));
        $this->registerDefault(VisitCounter::class, new VisitCounter());
        $this->container->lock();
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls[] = 'handle';
        $factory = new Psr17Factory();

        return match ($request->getUri()->getPath()) {
            '/hello' => $factory
                ->createResponse(200)
                ->withHeader('x-fixture', 'yes')
                ->withBody($factory->createStream('hello')),
            '/time' => $this->json(['now' => $this->clock()->now()->format(DATE_ATOM)]),
            '/visits' => $this->json(['visits' => $this->counter()->visit()]),
            '/echo' => $this->json($this->describe($request)),
            '/upstream' => $this->upstream(),
            '/boom' => throw new RuntimeException('boom'),
            default => $factory->createResponse(404),
        };
    }

    #[\Override]
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $this->calls[] = 'terminate';
    }

    #[\Override]
    public function reset(): void
    {
        $this->calls[] = 'reset';
        $this->container->reset();
    }

    private function registerDefault(string $id, object $service): void
    {
        if (!$this->container->has($id)) {
            $this->container->set($id, $service);
        }
    }

    private function json(array $data): ResponseInterface
    {
        $factory = new Psr17Factory();

        return $factory
            ->createResponse(200)
            ->withHeader('content-type', 'application/json')
            ->withBody($factory->createStream(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    private function describe(ServerRequestInterface $request): array
    {
        $server = $request->getServerParams();
        $keep = [
            'REQUEST_METHOD',
            'REQUEST_URI',
            'QUERY_STRING',
            'SERVER_NAME',
            'SERVER_PORT',
            'SERVER_PROTOCOL',
            'REMOTE_ADDR',
            'HTTPS',
            'HTTP_HOST',
            'HTTP_X_API_KEY',
            'HTTP_ACCEPT',
            'CONTENT_TYPE',
            'CONTENT_LENGTH',
        ];

        return [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'host' => $request->getHeaderLine('host'),
            'accept' => $request->getHeaderLine('accept'),
            'contentType' => $request->getHeaderLine('content-type'),
            'query' => $request->getQueryParams(),
            'cookies' => $request->getCookieParams(),
            'parsedBody' => $request->getParsedBody(),
            'body' => (string) $request->getBody(),
            'server' => array_intersect_key($server, array_flip($keep)),
        ];
    }

    private function upstream(): ResponseInterface
    {
        $client = $this->container->get(ClientInterface::class);
        if (!$client instanceof ClientInterface) {
            throw new LogicException('The container holds no HTTP client.');
        }

        $factory = new Psr17Factory();
        $answer = $client->sendRequest($factory->createRequest('GET', 'https://upstream.test/data'));

        return $factory
            ->createResponse($answer->getStatusCode())
            ->withBody($factory->createStream((string) $answer->getBody()));
    }

    private function clock(): ClockInterface
    {
        $clock = $this->container->get(ClockInterface::class);
        if (!$clock instanceof ClockInterface) {
            throw new LogicException('The container holds no clock.');
        }

        return $clock;
    }

    private function counter(): VisitCounter
    {
        $counter = $this->container->get(VisitCounter::class);
        if (!$counter instanceof VisitCounter) {
            throw new LogicException('The container holds no visit counter.');
        }

        return $counter;
    }
}
