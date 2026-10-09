<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Fixture;

use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;

/**
 * An object-only container that locks like the real one: `set()` fails once
 * `lock()` ran, so a double swapped in too late is caught.
 */
final class FixtureContainer implements ContainerInterface
{
    public bool $locked = false;

    /** @var array<string, object> */
    private array $services = [];

    /** @var array<string, true> */
    private array $failing = [];

    #[\Override]
    public function get(string $id): object
    {
        if (array_key_exists($id, $this->failing)) {
            throw new class('The entry cannot be built.') extends RuntimeException implements
                ContainerExceptionInterface {};
        }

        return (
            $this->services[$id] ?? throw new class('No entry for ' . $id . '.') extends RuntimeException implements
                NotFoundExceptionInterface {}
        );
    }

    #[\Override]
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services) || array_key_exists($id, $this->failing);
    }

    #[\Override]
    public function set(string $id, object|callable|string $concrete): void
    {
        if ($this->locked) {
            throw new LogicException('The container is locked: ' . $id . ' was registered too late.');
        }

        if (!is_object($concrete)) {
            throw new LogicException('The fixture container only holds objects.');
        }

        $this->services[$id] = $concrete;
    }

    /**
     * Registers an entry whose construction fails.
     */
    public function setFailing(string $id): void
    {
        $this->failing[$id] = true;
    }

    public function lock(): void
    {
        $this->locked = true;
    }

    #[\Override]
    public function reset(): void
    {
        foreach ($this->services as $service) {
            if (!$service instanceof ResettableInterface) {
                continue;
            }

            $service->reset();
        }
    }
}
