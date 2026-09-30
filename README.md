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

> **Release:** _unreleased_ &nbsp;|&nbsp; [`CHANGELOG.md`](./CHANGELOG.md)
> **Status:** In development for `0.1.0-beta7` — roadmap `[TEST-01]`. A dev-only package (`composer require --dev`); its test doubles implement the same `waffle-commons/contracts` interfaces as production code.

Kernel testing bridge for the Waffle framework: in-process request dispatch through the real pipeline, plus time, queue, mailer and HTTP-client test doubles.

## 📦 Installation

```bash
composer require waffle-commons/testing
```

## 🧭 Architectural boundary (`mago guard`)

`Waffle\Commons\Testing` depends only on `waffle-commons/contracts` (plus `waffle-commons/utils`
for shared primitives) and the PSR interfaces — never on a sibling component's concrete classes. Every
interface it implements lands in `waffle-commons/contracts` first.

## 🧵 Worker safety

Services are resident in FrankenPHP worker mode: no mutable state may survive a request. Anything that
holds per-request state implements `ResettableInterface` directly; `composer igor` must report 0 KO.

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
