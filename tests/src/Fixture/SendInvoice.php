<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Testing\Fixture;

use Waffle\Commons\Contracts\Queue\MessageInterface;

final readonly class SendInvoice implements MessageInterface
{
    public function __construct(
        public string $invoiceId,
    ) {}
}
