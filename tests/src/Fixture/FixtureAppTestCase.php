<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Fixture;

use Closure;
use Waffle\Commons\Contracts\Container\ContainerInterface;
use Waffle\Commons\Contracts\Core\KernelInterface;
use Waffle\Commons\Testing\Kernel\KernelClient;
use Waffle\Commons\Testing\PHPUnit\WaffleTestCase;

/**
 * What an application writes: a WaffleTestCase that builds its kernel. It is
 * never run as a test (its file does not end in `Test.php`); the tests drive it.
 */
final class FixtureAppTestCase extends WaffleTestCase
{
    /** @var list<FixtureKernel> */
    public array $kernels = [];

    /**
     * @param null|Closure(ContainerInterface): void $configure
     */
    public function client(?Closure $configure = null): KernelClient
    {
        return $this->createClient($configure);
    }

    #[\Override]
    protected function createKernel(): KernelInterface
    {
        $kernel = new FixtureKernel();
        $this->kernels[] = $kernel;

        return $kernel;
    }
}
