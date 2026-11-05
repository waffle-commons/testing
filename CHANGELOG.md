# Changelog — waffle-commons/testing

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [0.1.0-beta7] — 2026-11

**Theme: first release — the in-process kernel testing bridge and its test doubles (TEST-01).**

### Added
- Initial scaffold from `waffle-commons/component-template`: CI pipeline (Mago, Igor, PHPUnit,
  TruffleHog, SBOM, Psalm taint analysis), Dependabot, Mago zero-baseline configuration.
- **Kernel testing bridge (`[TEST-01]`):**
  - `Kernel\KernelClient` — sends requests through an application kernel in-process. `boot()` gives the
    test one chance to swap doubles into the container between `boot()` and `configure()`, before it
    locks (`[CARRY-04]`). Every request runs the worker's sequence — handle, terminate, reset — even when
    the kernel throws. Requests are built with the container's PSR-17 factories and carry what the
    production `GlobalsFactory` derives (server parameters, query, cookies, and a parsed body for `POST`
    forms and JSON only, as in production). `request()`, `jsonRequest()`, `createRequest()`, `send()`.
  - `Kernel\TestResponse` — the response with its body read once: `status()`, `header()`, `json()`.
  - `PHPUnit\WaffleTestCase` — the PHPUnit base class: abstract `createKernel()`, `createClient()` (a
    fresh kernel per client), `assertStatus()`, `assertHeader()`, `assertJsonBody()`.
- **Test doubles:**
  - `Clock\FrozenClock` — a PSR-20 clock that stands still until `set()` or `advance()` moves it.
  - `Http\ReplayClient` — a PSR-18 and `ConcurrentClientInterface` double that replays queued answers
    (responses or simulated network failures) per method and URI and records every request; an
    unplanned request throws `Exception\UnexpectedRequestException`. `Http\SettledPromise` backs its
    concurrent path.
  - `Queue\InMemoryQueue` — a dispatcher and consumer held in memory (`[QUEUE-01]` contracts): it records
    every dispatched envelope, serves deliveries by the injected clock without blocking, settles them, and
    `reclaim()` plays the recovery after a worker crash.
  - `Queue\InMemoryFailedMessageStore` — the dead-letter store in memory.
  - `Mailer\MailerSpy` — records every `Email` instead of sending it (`[QUEUE-03]` contract).
  - `RateLimit\InMemoryLimiterStateStorage` — the `[NET-01]` limiter store in memory: the Redis store's
    token-bucket arithmetic on the injected clock, so a test drives refills with a frozen clock. Correct for
    one process only, which is why it is a test double and not a production store.
- Exceptions: `MissingServiceException`, `UnexpectedRequestException`, `SimulatedNetworkException`
  (a PSR-18 network exception), `InvalidJsonException`, `UnknownDeliveryException` (a queue exception).

### Changed
- `phpunit/phpunit` ^12.5 moved to `require` (`[CARRY-04]`); the `mago guard` perimeter permits
  `PHPUnit\**` in `Waffle\Commons\Testing\PHPUnit` only, and the igor `#[WorkerSafe]` attribute.
  `psr/clock`, `psr/http-client`, `psr/http-factory` and `psr/http-message` are direct dependencies;
  `nyholm/psr7` is a development dependency for the component's own tests.
