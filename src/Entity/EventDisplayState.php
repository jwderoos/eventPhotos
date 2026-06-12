<?php

declare(strict_types=1);

namespace App\Entity;

enum EventDisplayState: string
{
    case Pre  = 'pre';
    case Live = 'live';
    case Post = 'post';
}
