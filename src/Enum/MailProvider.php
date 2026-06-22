<?php

declare(strict_types=1);

namespace App\Enum;

enum MailProvider: string
{
    case Custom = 'custom';
    case Gmail = 'gmail';
}
