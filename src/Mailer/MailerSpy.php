<?php

declare(strict_types=1);

namespace Waffle\Commons\Testing\Mailer;

use IgorPhp\IgorBundle\Attribute\WorkerSafe;
use Waffle\Commons\Contracts\Mailer\Email;
use Waffle\Commons\Contracts\Mailer\MailerInterface;

/**
 * A mailer that sends nothing and records every email, for tests ([TEST-01]).
 */
final class MailerSpy implements MailerInterface
{
    /**
     * @var list<Email>
     */
    #[WorkerSafe(
        scope: 'test',
        reason: 'test double: one instance per test, never resident in a worker; it records what the test asserts',
    )]
    private array $sent = [];

    #[\Override]
    public function send(Email $email): void
    {
        $this->sent[] = $email;
    }

    /**
     * Every email sent, in order.
     *
     * @return list<Email>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
