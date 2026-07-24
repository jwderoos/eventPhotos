<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SendSubscriptionConfirmationEmail
{
    public function __construct(public int $subscriptionId)
    {
    }
}
