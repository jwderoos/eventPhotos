<?php

declare(strict_types=1);

namespace App\Entity;

enum EventNotificationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Unsubscribed = 'unsubscribed';
}
