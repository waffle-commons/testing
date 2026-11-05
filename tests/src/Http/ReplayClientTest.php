<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Http;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface as PsrNetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Waffle\Commons\Contracts\HttpClient\ConcurrentClientInterface;
use Waffle\Commons\Contracts\HttpClient\Enum\PromiseState;
use Waffle\Commons\Contracts\HttpClient\Exception\NetworkExceptionInterface;
use Waffle\Commons\Testing\Exception\SimulatedNetworkException;
use Waffle\Commons\Testing\Exception\UnexpectedRequestException;
use Waffle\Commons\Testing\Http\ReplayClient;
use Waffle\Commons\Testing\Http\SettledPromise;
use WaffleTests\Commons\Testing\AbstractTestCase as TestCase;

#[CoversClass(ReplayClient::class)]
#[CoversClass(SimulatedNetworkException::class)]
#[CoversClass(UnexpectedRequestException::class)]
#[CoversClass(SettledPromise::class)]
final class ReplayClientTest extends TestCase
{
    private const string URI = 'https://upstream.test/data';

    public function testImplementsBothClientContracts(): void
    {
        $client = new ReplayClient();

        static::assertInstanceOf(ClientInterface::class, $client);
        static::assertInstanceOf(ConcurrentClientInterface::class, $client);
    }

    public function testServesTheResponseQueuedForTheMethodAndUri(): void
    {
        $client = new ReplayClient();
        $response = new Response(200, [], 'payload');
        $client->addResponse('GET', self::URI, $response);

        static::assertSame($response, $client->sendRequest(new Request('GET', self::URI)));
    }

    public function testMatchesTheMethodWhateverItsCase(): void
    {
        $client = new ReplayClient();
        $response = new Response(200);
        $client->addResponse('post', self::URI, $response);

        static::assertSame($response, $client->sendRequest(new Request('POST', self::URI)));
    }

    public function testServesEachAnswerOnceAndInOrder(): void
    {
        $client = new ReplayClient();
        $first = new Response(503);
        $second = new Response(200);
        $client->addResponse('GET', self::URI, $first);
        $client->addResponse('GET', self::URI, $second);

        static::assertSame($first, $client->sendRequest(new Request('GET', self::URI)));
        static::assertSame($second, $client->sendRequest(new Request('GET', self::URI)));

        $this->expectException(UnexpectedRequestException::class);
        $client->sendRequest(new Request('GET', self::URI));
    }

    public function testARequestWithNoAnswerFailsTheTestInsteadOfLookingLikeAnOutage(): void
    {
        $client = new ReplayClient();
        $client->addResponse('GET', self::URI, new Response(200));

        try {
            $client->sendRequest(new Request('DELETE', self::URI));
            static::fail('An unplanned request must throw.');
        } catch (UnexpectedRequestException $exception) {
            static::assertNotInstanceOf(\Psr\Http\Client\ClientExceptionInterface::class, $exception);
            static::assertSame('DELETE', $exception->method);
            static::assertSame(self::URI, $exception->uri);
            static::assertSame(
                'The replay client has no response left for DELETE https://upstream.test/data.',
                $exception->getMessage(),
            );
        }
    }

    public function testAQueuedNetworkErrorThrowsAPsr18NetworkException(): void
    {
        $client = new ReplayClient();
        $client->addNetworkError('GET', self::URI);
        $request = new Request('GET', self::URI);

        try {
            $client->sendRequest($request);
            static::fail('A queued network error must throw.');
        } catch (SimulatedNetworkException $exception) {
            static::assertInstanceOf(PsrNetworkExceptionInterface::class, $exception);
            static::assertInstanceOf(NetworkExceptionInterface::class, $exception);
            static::assertSame($request, $exception->getRequest());
            static::assertSame(
                'Simulated network failure for GET https://upstream.test/data.',
                $exception->getMessage(),
            );
        }
    }

    public function testRecordsEveryRequestAnsweredOrNot(): void
    {
        $client = new ReplayClient();
        $client->addResponse('GET', self::URI, new Response(200));
        $answered = new Request('GET', self::URI);
        $unplanned = new Request('GET', 'https://upstream.test/other');

        $client->sendRequest($answered);
        try {
            $client->sendRequest($unplanned);
            static::fail('An unplanned request must throw.');
        } catch (UnexpectedRequestException $exception) {
            static::assertSame('https://upstream.test/other', $exception->uri);
        }

        static::assertSame([$answered, $unplanned], $client->requests());
    }

    public function testCountsTheAnswersNoRequestHasTakenYet(): void
    {
        $client = new ReplayClient();
        static::assertSame(0, $client->pending());

        $client->addResponse('GET', self::URI, new Response(200));
        $client->addResponse('GET', self::URI, new Response(200));
        $client->addNetworkError('POST', self::URI);
        static::assertSame(3, $client->pending());

        $client->sendRequest(new Request('GET', self::URI));
        static::assertSame(2, $client->pending());
    }

    public function testSendRequestsAnswersEachRequestAndKeepsItsKey(): void
    {
        $client = new ReplayClient();
        $users = new Response(200, [], 'users');
        $orders = new Response(200, [], 'orders');
        $client->addResponse('GET', 'https://upstream.test/users', $users);
        $client->addResponse('GET', 'https://upstream.test/orders', $orders);

        $responses = $client->sendRequests([
            'orders' => new Request('GET', 'https://upstream.test/orders'),
            'users' => new Request('GET', 'https://upstream.test/users'),
        ]);

        static::assertSame(['orders' => $orders, 'users' => $users], $responses);
    }

    public function testAPromiseIsFulfilledWithTheQueuedResponse(): void
    {
        $client = new ReplayClient();
        $response = new Response(200);
        $client->addResponse('GET', self::URI, $response);

        $promise = $client->promise(new Request('GET', self::URI));

        static::assertSame(PromiseState::Fulfilled, $promise->state());
        static::assertSame($response, $promise->wait());
    }

    public function testAPromiseIsRejectedByAQueuedNetworkError(): void
    {
        $client = new ReplayClient();
        $client->addNetworkError('GET', self::URI);
        $failures = [];

        $promise = $client->promise(new Request('GET', self::URI));
        $promise->catch(static function (Throwable $failure) use (&$failures): void {
            $failures[] = $failure;
        });

        static::assertSame(PromiseState::Rejected, $promise->state());
        static::assertCount(1, $failures);
        static::assertInstanceOf(SimulatedNetworkException::class, $failures[0]);
    }

    public function testAPromiseForAnUnplannedRequestThrowsAtOnce(): void
    {
        $client = new ReplayClient();

        $this->expectException(UnexpectedRequestException::class);

        $client->promise(new Request('GET', self::URI));
    }

    public function testAPromiseHandsTheResponseToItsCallback(): void
    {
        $client = new ReplayClient();
        $response = new Response(201);
        $client->addResponse('PUT', self::URI, $response);
        $received = [];

        $client
            ->promise(new Request('PUT', self::URI))
            ->then(static function (ResponseInterface $answer) use (&$received): void {
                $received[] = $answer;
            });

        static::assertSame([$response], $received);
    }
}
