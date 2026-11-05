<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Kernel;

use InvalidArgumentException;
use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use stdClass;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Testing\Clock\FrozenClock;
use Waffle\Commons\Testing\Exception\MissingServiceException;
use Waffle\Commons\Testing\Http\ReplayClient;
use Waffle\Commons\Testing\Kernel\KernelClient;
use Waffle\Commons\Testing\Kernel\TestResponse;
use WaffleTests\Commons\Testing\AbstractTestCase as TestCase;
use WaffleTests\Commons\Testing\Fixture\FixtureContainer;
use WaffleTests\Commons\Testing\Fixture\FixtureKernel;

#[CoversClass(KernelClient::class)]
#[CoversClass(TestResponse::class)]
#[CoversClass(MissingServiceException::class)]
final class KernelClientTest extends TestCase
{
    public function testBootsThenLetsTheTestSwapDoublesBeforeTheContainerLocks(): void
    {
        $kernel = new FixtureKernel();
        $clock = new FrozenClock('2026-10-09T09:00:00+00:00');
        $seen = [];

        $client = KernelClient::boot($kernel, static function (ContainerInterface $container) use (
            $kernel,
            $clock,
            &$seen,
        ): void {
            $seen = $kernel->calls;
            $container->set(ClockInterface::class, $clock);
        });

        static::assertSame(['boot'], $seen);
        static::assertSame(['boot', 'configure'], $kernel->calls);
        static::assertTrue($kernel->container->locked);
        static::assertSame($kernel->container, $client->container());
        static::assertSame(['now' => '2026-10-09T09:00:00+00:00'], $client->request('GET', '/time')->json());
    }

    public function testWithoutDoublesTheKernelKeepsItsOwnServices(): void
    {
        $client = KernelClient::boot(new FixtureKernel());

        static::assertSame(['now' => FixtureKernel::DEFAULT_TIME], $client->request('GET', '/time')->json());
    }

    public function testATestMovesTheFrozenClockBetweenRequests(): void
    {
        $clock = new FrozenClock('2026-10-09T09:00:00+00:00');
        $client = KernelClient::boot(new FixtureKernel(), static function (ContainerInterface $container) use (
            $clock,
        ): void {
            $container->set(ClockInterface::class, $clock);
        });

        $client->request('GET', '/time');
        $clock->advance('+1 hour');

        static::assertSame(['now' => '2026-10-09T10:00:00+00:00'], $client->request('GET', '/time')->json());
    }

    public function testTheReplayClientAnswersTheKernelsOutboundCalls(): void
    {
        $http = new ReplayClient();
        $http->addResponse('GET', 'https://upstream.test/data', new Response(202, [], 'from upstream'));
        $client = KernelClient::boot(new FixtureKernel(), static function (ContainerInterface $container) use (
            $http,
        ): void {
            $container->set(ClientInterface::class, $http);
        });

        $response = $client->request('GET', '/upstream');

        static::assertSame(202, $response->status());
        static::assertSame('from upstream', $response->body);
        static::assertCount(1, $http->requests());
        static::assertSame(0, $http->pending());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function factories(): iterable
    {
        yield 'request factory' => [ServerRequestFactoryInterface::class];
        yield 'stream factory' => [StreamFactoryInterface::class];
    }

    #[DataProvider('factories')]
    public function testRefusesAKernelWhoseContainerLacksAFactory(string $factory): void
    {
        $container = new FixtureContainer();
        $other = $factory === ServerRequestFactoryInterface::class
            ? StreamFactoryInterface::class
            : ServerRequestFactoryInterface::class;
        $container->set($other, new Psr17Factory());

        try {
            KernelClient::boot(new FixtureKernel($container, registerFactories: false));
            static::fail('A container without the PSR-17 factories must be refused.');
        } catch (MissingServiceException $exception) {
            static::assertSame($factory, $exception->service);
            static::assertSame(
                'The kernel container provides no "' . $factory . '"; register one in the application kernel factory.',
                $exception->getMessage(),
            );
        }
    }

    public function testRefusesAFactoryEntryOfTheWrongType(): void
    {
        $this->expectException(MissingServiceException::class);

        KernelClient::boot(new FixtureKernel(), static function (ContainerInterface $container): void {
            $container->set(ServerRequestFactoryInterface::class, new stdClass());
        });
    }

    public function testRefusesAFactoryEntryThatCannotBeBuilt(): void
    {
        $container = new FixtureContainer();
        $container->setFailing(ServerRequestFactoryInterface::class);

        try {
            KernelClient::boot(new FixtureKernel($container));
            static::fail('An entry that cannot be built must be refused.');
        } catch (MissingServiceException $exception) {
            static::assertInstanceOf(ContainerExceptionInterface::class, $exception->getPrevious());
        }
    }

    public function testEachRequestRunsTheWorkersSequence(): void
    {
        $kernel = new FixtureKernel();
        $client = KernelClient::boot($kernel);

        $response = $client->request('GET', '/hello');

        static::assertSame(['boot', 'configure', 'handle', 'terminate', 'reset'], $kernel->calls);
        static::assertSame(200, $response->status());
        static::assertSame('hello', $response->body);
        static::assertSame('yes', $response->header('x-fixture'));
    }

    public function testTheKernelIsResetEvenWhenItThrows(): void
    {
        $kernel = new FixtureKernel();
        $client = KernelClient::boot($kernel);

        try {
            $client->request('GET', '/boom');
            static::fail('The kernel failure must reach the test.');
        } catch (RuntimeException $exception) {
            static::assertSame('boom', $exception->getMessage());
        }

        static::assertSame(['boot', 'configure', 'handle', 'reset'], $kernel->calls);
    }

    public function testAKernelThatIsNotTerminableIsHandledThenReset(): void
    {
        $inner = new FixtureKernel();
        $kernel = new readonly class($inner) implements KernelInterface {
            public ContainerInterface $container;

            public function __construct(
                private FixtureKernel $inner,
            ) {
                $this->container = $inner->container;
            }

            #[\Override]
            public function boot(): static
            {
                $this->inner->boot();

                return $this;
            }

            #[\Override]
            public function configure(): void
            {
                $this->inner->configure();
            }

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->inner->handle($request);
            }

            #[\Override]
            public function reset(): void
            {
                $this->inner->reset();
            }
        };

        KernelClient::boot($kernel)->request('GET', '/hello');

        static::assertSame(['boot', 'configure', 'handle', 'reset'], $inner->calls);
    }

    public function testSendingTheSameRequestTwiceShowsNoLeakedState(): void
    {
        $client = KernelClient::boot(new FixtureKernel());

        static::assertSame(['visits' => 1], $client->request('GET', '/visits')->json());
        static::assertSame(['visits' => 1], $client->request('GET', '/visits')->json());
    }

    public function testBuildsTheRequestAWorkerReceives(): void
    {
        $client = KernelClient::boot(new FixtureKernel());

        $request = $client->createRequest(
            'get',
            '/search?q=waffle&page=2',
            ['X-Api-Key' => 'secret', 'Cookie' => 'a=1; b=two%20words; a=ignored; broken; =nameless'],
            '',
            ['REMOTE_ADDR' => '10.0.0.7'],
        );

        static::assertSame('GET', $request->getMethod());
        static::assertSame('http://localhost/search?q=waffle&page=2', (string) $request->getUri());
        static::assertSame('localhost', $request->getHeaderLine('host'));
        static::assertSame('secret', $request->getHeaderLine('x-api-key'));
        static::assertSame(['q' => 'waffle', 'page' => '2'], $request->getQueryParams());
        static::assertSame(['a' => '1', 'b' => 'two words'], $request->getCookieParams());
        static::assertNull($request->getParsedBody());
        static::assertSame('', (string) $request->getBody());

        $server = $request->getServerParams();
        static::assertSame('GET', $server['REQUEST_METHOD']);
        static::assertSame('/search?q=waffle&page=2', $server['REQUEST_URI']);
        static::assertSame('q=waffle&page=2', $server['QUERY_STRING']);
        static::assertSame('localhost', $server['SERVER_NAME']);
        static::assertSame('80', $server['SERVER_PORT']);
        static::assertSame('HTTP/1.1', $server['SERVER_PROTOCOL']);
        static::assertSame('10.0.0.7', $server['REMOTE_ADDR']);
        static::assertSame('localhost', $server['HTTP_HOST']);
        static::assertSame('secret', $server['HTTP_X_API_KEY']);
        static::assertArrayNotHasKey('HTTPS', $server);
        static::assertArrayNotHasKey('CONTENT_LENGTH', $server);
    }

    public function testAnAbsoluteHttpsUriSetsTheSchemePortAndHost(): void
    {
        $client = KernelClient::boot(new FixtureKernel());

        $explicit = $client->createRequest('GET', 'https://api.example.test:8443/v1/items')->getServerParams();
        static::assertSame('on', $explicit['HTTPS']);
        static::assertSame('8443', $explicit['SERVER_PORT']);
        static::assertSame('api.example.test', $explicit['SERVER_NAME']);
        static::assertSame('api.example.test:8443', $explicit['HTTP_HOST']);
        static::assertSame('/v1/items', $explicit['REQUEST_URI']);

        $default = $client->createRequest('GET', 'https://api.example.test');
        static::assertSame('443', $default->getServerParams()['SERVER_PORT']);
        static::assertSame('/', $default->getServerParams()['REQUEST_URI']);
        static::assertSame('api.example.test', $default->getHeaderLine('host'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uriWithoutHost(): iterable
    {
        yield 'relative path' => ['users/42'];
        yield 'unparsable' => ['http:///users'];
    }

    #[DataProvider('uriWithoutHost')]
    public function testRefusesAUriThatIsNeitherAPathNorAbsolute(string $uri): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The request URI must be a path or an absolute URI with a host, got "' . $uri . '".',
        );

        KernelClient::boot(new FixtureKernel())->createRequest('GET', $uri);
    }

    public function testAPostedJsonBodyIsParsedAsInProduction(): void
    {
        $client = KernelClient::boot(new FixtureKernel());

        $echo = $client->jsonRequest('POST', '/echo', ['name' => 'Ada', 'tags' => ['a'], 'ratio' => 1.0])->json();

        static::assertSame('POST', $echo['method']);
        static::assertSame('application/json', $echo['contentType']);
        static::assertSame('application/json', $echo['accept']);
        static::assertSame('{"name":"Ada","tags":["a"],"ratio":1.0}', $echo['body']);
        static::assertSame(['name' => 'Ada', 'tags' => ['a'], 'ratio' => 1], $echo['parsedBody']);

        $server = $echo['server'];
        static::assertIsArray($server);
        static::assertSame('39', $server['CONTENT_LENGTH']);
        static::assertSame('application/json', $server['CONTENT_TYPE']);
    }

    public function testAJsonBodySentWithAnotherMethodIsNotParsedAsInProduction(): void
    {
        $client = KernelClient::boot(new FixtureKernel());

        $echo = $client->jsonRequest('PUT', '/echo', ['name' => 'Ada'])->json();

        static::assertSame('{"name":"Ada"}', $echo['body']);
        static::assertNull($echo['parsedBody']);
    }

    public function testAPostedFormIsParsed(): void
    {
        $client = KernelClient::boot(new FixtureKernel());

        $echo = $client
            ->request(
                'POST',
                '/echo',
                ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
                'name=Ada&tags%5B%5D=a',
            )
            ->json();

        static::assertSame(['name' => 'Ada', 'tags' => ['a']], $echo['parsedBody']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesLeftUnparsed(): iterable
    {
        yield 'malformed JSON' => ['application/json', '{oops'];
        yield 'scalar JSON' => ['application/json', '42'];
        yield 'empty JSON body' => ['application/json', ''];
        yield 'another media type' => ['text/plain', 'hello'];
    }

    #[DataProvider('bodiesLeftUnparsed')]
    public function testAPostedBodyProductionCannotParseLeavesTheParsedBodyNull(string $type, string $body): void
    {
        $echo = KernelClient::boot(new FixtureKernel())
            ->request('POST', '/echo', ['content-type' => $type], $body)
            ->json();

        static::assertNull($echo['parsedBody']);
    }

    public function testAMultipartBodyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The testing bridge does not build multipart/form-data bodies.');

        KernelClient::boot(new FixtureKernel())->request(
            'POST',
            '/echo',
            ['content-type' => 'multipart/form-data; boundary=x'],
            '--x--',
        );
    }

    public function testTheTestsHeadersWinOverTheJsonDefaults(): void
    {
        $echo = KernelClient::boot(new FixtureKernel())
            ->jsonRequest('POST', '/echo', [], ['Accept' => 'application/problem+json'])
            ->json();

        static::assertSame('application/problem+json', $echo['accept']);

        $server = $echo['server'];
        static::assertIsArray($server);
        static::assertSame('application/problem+json', $server['HTTP_ACCEPT']);
    }

    public function testDataJsonCannotEncodeIsRefused(): void
    {
        $this->expectException(JsonException::class);

        KernelClient::boot(new FixtureKernel())->jsonRequest('POST', '/echo', ['name' => "\xB1\x31"]);
    }

    public function testSendsAPreparedRequest(): void
    {
        $client = KernelClient::boot(new FixtureKernel());

        $response = $client->send($client->createRequest('GET', '/hello'));

        static::assertSame('hello', $response->body);
    }
}
