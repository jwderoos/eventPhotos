<?php

declare(strict_types=1);

namespace App\Audit;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class AuditContext
{
    private const string ATTR_CONTEXT = '_audit_context';

    private const string ATTR_SUPPRESSED = '_audit_suppressed';

    private const string KEY_TARGET_LABEL = '_target_label';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function set(string $key, mixed $value): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return;
        }

        /** @var array<string, mixed> $context */
        $context = $request->attributes->get(self::ATTR_CONTEXT, []);
        $context[$key] = $value;
        $request->attributes->set(self::ATTR_CONTEXT, $context);
    }

    public function changed(string $field, mixed $old, mixed $new): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return;
        }

        /** @var array<string, mixed> $context */
        $context = $request->attributes->get(self::ATTR_CONTEXT, []);
        /** @var array<string, array{mixed, mixed}> $changes */
        $changes = $context['changes'] ?? [];
        $changes[$field] = [$old, $new];
        $context['changes'] = $changes;
        $request->attributes->set(self::ATTR_CONTEXT, $context);
    }

    /** @param array<string, mixed> $fields */
    public function snapshot(array $fields): void
    {
        $this->set('snapshot', $fields);
    }

    public function targetLabel(string $label): void
    {
        $this->set(self::KEY_TARGET_LABEL, $label);
    }

    public function suppress(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $request?->attributes->set(self::ATTR_SUPPRESSED, true);
    }

    public function isSuppressed(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request instanceof Request && $this->isSuppressedOnRequest($request);
    }

    public function isSuppressedOnRequest(Request $request): bool
    {
        return $request->attributes->getBoolean(self::ATTR_SUPPRESSED);
    }

    public function pulledTargetLabel(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request instanceof Request ? $this->pulledTargetLabelFromRequest($request) : null;
    }

    public function pulledTargetLabelFromRequest(Request $request): ?string
    {
        /** @var array<string, mixed> $context */
        $context = $request->attributes->get(self::ATTR_CONTEXT, []);
        $label = $context[self::KEY_TARGET_LABEL] ?? null;

        return is_string($label) ? $label : null;
    }

    /** @return array<string, mixed> */
    public function pull(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request instanceof Request ? $this->pullFromRequest($request) : [];
    }

    /** @return array<string, mixed> */
    public function pullFromRequest(Request $request): array
    {
        /** @var array<string, mixed> $context */
        $context = $request->attributes->get(self::ATTR_CONTEXT, []);
        unset($context[self::KEY_TARGET_LABEL]);

        return $context;
    }
}
