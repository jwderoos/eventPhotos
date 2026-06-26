<?php

declare(strict_types=1);

namespace App\Audit\Attribute;

use App\Audit\AuditAction;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Audited
{
    public function __construct(
        public AuditAction $action,
        public ?string $targetParam = null,
        public ?string $targetType = null,
    ) {
    }
}
