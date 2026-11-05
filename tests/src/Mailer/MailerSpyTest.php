<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Mailer;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Mailer\Email;
use Waffle\Commons\Contracts\Mailer\MailerInterface;
use Waffle\Commons\Testing\Mailer\MailerSpy;
use WaffleTests\Commons\Testing\AbstractTestCase as TestCase;

#[CoversClass(MailerSpy::class)]
final class MailerSpyTest extends TestCase
{
    public function testRecordsEveryEmailInsteadOfSendingIt(): void
    {
        $mailer = new MailerSpy();
        $welcome = new Email('hello@example.com', ['ada@example.com'], 'Welcome', text: 'Hello Ada.');
        $receipt = new Email('billing@example.com', ['ada@example.com'], 'Your receipt', html: '<p>Thanks.</p>');

        $mailer->send($welcome);
        $mailer->send($receipt);

        static::assertInstanceOf(MailerInterface::class, $mailer);
        static::assertSame([$welcome, $receipt], $mailer->sent());
    }
}
