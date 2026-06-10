<?php

declare(strict_types=1);

namespace App\Entity;

enum PhotoStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
