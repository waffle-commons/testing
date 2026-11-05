<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Kernel;

use JsonException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Testing\Exception\InvalidJsonException;
use Waffle\Commons\Testing\Kernel\TestResponse;
use WaffleTests\Commons\Testing\AbstractTestCase as TestCase;

#[CoversClass(TestResponse::class)]
#[CoversClass(InvalidJsonException::class)]
final class TestResponseTest extends TestCase
{
    public function testReadsTheStatusAndTheHeaders(): void
    {
        $response = new TestResponse(new Response(201, ['X-Trace' => ['a', 'b']]), '');

        static::assertSame(201, $response->status());
        static::assertTrue($response->hasHeader('x-trace'));
        static::assertSame('a, b', $response->header('X-Trace'));
        static::assertFalse($response->hasHeader('X-Missing'));
        static::assertSame('', $response->header('X-Missing'));
    }

    public function testDecodesAJsonObjectOrList(): void
    {
        static::assertSame(
            ['id' => 42, 'tags' => ['a']],
            new TestResponse(new Response(), '{"id":42,"tags":["a"]}')->json(),
        );
        static::assertSame([1, 2], new TestResponse(new Response(), '[1,2]')->json());
        static::assertSame([], new TestResponse(new Response(), '{}')->json());
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        try {
            new TestResponse(new Response(), '<html>')->json();
            static::fail('A body that is not JSON must be refused.');
        } catch (InvalidJsonException $exception) {
            static::assertStringStartsWith(
                'The response body is not a JSON object or list: ',
                $exception->getMessage(),
            );
            static::assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scalarJson(): iterable
    {
        yield 'number' => ['42'];
        yield 'string' => ['"ok"'];
        yield 'null' => ['null'];
    }

    #[DataProvider('scalarJson')]
    public function testJsonThatIsNotAnObjectOrListIsRefused(string $body): void
    {
        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage(
            'The response body is not a JSON object or list: it decodes to a scalar or null.',
        );

        new TestResponse(new Response(), $body)->json();
    }
}
