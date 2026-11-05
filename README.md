[![Discord](https://img.shields.io/discord/755288001592033391?logo=discord)](https://discord.gg/eKgywnfXr2)
[![PHP Version Require](http://poser.pugx.org/waffle-commons/testing/require/php)](https://packagist.org/packages/waffle-commons/testing)
[![PHP CI](https://github.com/waffle-commons/testing/actions/workflows/main.yml/badge.svg)](https://github.com/waffle-commons/testing/actions/workflows/main.yml)
[![codecov](https://codecov.io/gh/waffle-commons/testing/graph/badge.svg)](https://codecov.io/gh/waffle-commons/testing)
[![Latest Stable Version](http://poser.pugx.org/waffle-commons/testing/v)](https://packagist.org/packages/waffle-commons/testing)
[![Latest Unstable Version](http://poser.pugx.org/waffle-commons/testing/v/unstable)](https://packagist.org/packages/waffle-commons/testing)
[![Total Downloads](https://img.shields.io/packagist/dt/waffle-commons/testing.svg)](https://packagist.org/packages/waffle-commons/testing)
[![Packagist License](https://img.shields.io/packagist/l/waffle-commons/testing)](https://github.com/waffle-commons/testing/blob/main/LICENSE.md)

Waffle Testing Component
==========================

> **Release:** `0.1.0-beta7` &nbsp;|&nbsp; [`CHANGELOG.md`](./CHANGELOG.md)
> **Status:** First released in `0.1.0-beta7` — roadmap `[TEST-01]`. A dev-only package (`composer require --dev`); its test doubles implement the same `waffle-commons/contracts` interfaces as production code.

Kernel testing bridge for the Waffle framework: in-process request dispatch through the real pipeline, plus time, queue, mailer and HTTP-client test doubles.

## 📦 Installation

```bash
composer require --dev waffle-commons/testing
```

## 🧪 Testing through the real pipeline

Extend `Waffle\Commons\Testing\PHPUnit\WaffleTestCase` and build your kernel in `createKernel()`.
Each `createClient()` boots a fresh kernel; the client sends requests through it in-process, with no
web server and no network:

```php
use App\Factory\AppKernelFactory;
use Psr\Clock\ClockInterface;
use Waffle\Commons\Contracts\Constant\Constant;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Testing\Clock\FrozenClock;
use Waffle\Commons\Testing\PHPUnit\WaffleTestCase;

final class OrderApiTest extends WaffleTestCase
{
    protected function createKernel(): KernelInterface
    {
        return AppKernelFactory::create(Constant::ENV_TEST);
    }

    public function testShowsAnOrder(): void
    {
        $clock = new FrozenClock('2026-10-09T09:00:00+00:00');
        $client = $this->createClient(static function (ContainerInterface $container) use ($clock): void {
            $container->set(ClockInterface::class, $clock);
        });

        $response = $client->request('GET', '/orders/42');

        self::assertStatus(200, $response);
        self::assertJsonBody(['id' => 42, 'status' => 'shipped'], $response);
    }
}
```

- **The worker's sequence.** Every request runs `handle()`, then `terminate()` when the kernel is
  terminable, then `reset()`, even when the kernel throws. Sending the same request twice shows
  whether any state leaks from one request into the next.
- **Doubles before the lock.** The closure given to `createClient()` runs between the kernel's `boot()`
  and `configure()`, before the container locks. A double reaches what the container resolves
  (controllers and their dependencies), not a collaborator your kernel factory built up front.
- **Requests as production builds them.** The client builds requests with the PSR-17
  `ServerRequestFactoryInterface` and `StreamFactoryInterface` from the kernel's container (your kernel
  factory registers them), and fills what the production `GlobalsFactory` derives: CGI server
  parameters, query parameters, cookies and the parsed body. Like production, a body is parsed only for
  `POST`: a urlencoded form or a JSON object. `jsonRequest()` sends `$data` as JSON;
  `createRequest()` + `send()` give full control (pass `REMOTE_ADDR` and other server parameters in
  `$server`). Multipart bodies are not built.
- **Assertions:** `assertStatus()` (quotes the body when the status differs), `assertHeader()`,
  `assertJsonBody()`. `TestResponse` also exposes `status()`, `header()`, `json()` and the raw `body`.

## 🎭 Test doubles

| Double | Stands in for | Behaviour |
| :--- | :--- | :--- |
| `Clock\FrozenClock` | `Psr\Clock\ClockInterface` | Shows one instant until the test calls `set()` or `advance('+90 seconds')`. Production code uses `Waffle\Commons\Utils\Clock\SystemClock`. |
| `Http\ReplayClient` | PSR-18 `ClientInterface` and `ConcurrentClientInterface` | Replays the answers the test queued per method and URI (`addResponse()`, `addNetworkError()`), each once and in order, and records every request (`requests()`, `pending()`). A request with no answer left throws `UnexpectedRequestException`, which is not a PSR-18 exception, so an unplanned call fails the test. |
| `Queue\InMemoryQueue` | `QueueDispatcherInterface` and `QueueConsumerInterface` | Records every dispatched envelope (`dispatched()`, by queue too) with deterministic ids, and serves a worker: `receive()` follows the injected clock and never blocks, `acknowledge()` / `release()` settle a delivery, `reclaim()` plays the recovery after a worker crash. |
| `Queue\InMemoryFailedMessageStore` | `FailedMessageStoreInterface` | Keeps what a worker gave up on — full envelope, reason, instant from the clock — oldest first. |
| `Mailer\MailerSpy` | `MailerInterface` | Sends nothing; records every `Email` (`sent()`). |
| `RateLimit\InMemoryLimiterStateStorage` | `LimiterStateStorageInterface` | Token buckets in memory, with the arithmetic of the Redis store (`RateLimit-*` values, `Retry-After`) on the injected clock: advance a `FrozenClock` to refill. One process only — production keeps limiter state in Redis, never in worker memory. |

## 🧭 Architectural boundary (`mago guard`)

`Waffle\Commons\Testing` depends only on `waffle-commons/contracts` and the PSR interfaces — never on a
sibling component's concrete classes. Every interface it implements lands in `waffle-commons/contracts`
first. PHPUnit is a runtime dependency of this dev-only package, and the guard permits it in
`Waffle\Commons\Testing\PHPUnit` only: the kernel client and the doubles work with any test runner.

## 🧵 Worker safety

The doubles are mutable on purpose: a test sets the time, queues answers and reads what was recorded.
They live for one test and are never resident in a worker, so each mutable property carries
`#[WorkerSafe]` with that reason, and they do not implement `ResettableInterface` (the kernel resets
after every request, which would erase what the test asserts). `composer igor` reports 0 KO.

## 🧪 Testing

```bash
composer mago    # formatter, linter, analyzer, guard — clean means zero output
composer tests   # PHPUnit 12.5, line coverage ≥ 95 %
composer igor    # worker-safety audit
```

## 📚 Documentation

Full documentation tree: [waffle-commons/documentation](https://github.com/waffle-commons/documentation)

## 📄 License

MIT — see [`LICENSE.md`](./LICENSE.md).
