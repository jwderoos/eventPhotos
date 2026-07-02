<?php

declare(strict_types=1);

namespace App\Service\Style;

use App\Entity\Event;
use App\Entity\StyleSettings;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;

final readonly class StyleResolver
{
    public function __construct(
        private OrganizerProfileRepository $profiles,
    ) {
    }

    public function resolve(Event $event): ResolvedStyle
    {
        return $this->resolveChain(
            $event->getStyle(),
            $event->getCollection()?->getStyle(),
            $this->profileStyleFor($event->getOwner()),
        );
    }

    public function resolveChain(?StyleSettings ...$tiers): ResolvedStyle
    {
        return new ResolvedStyle(
            $this->firstColor($tiers, static fn (StyleSettings $s): ?string => $s->getFontColor()),
            $this->firstColor($tiers, static fn (StyleSettings $s): ?string => $s->getBackgroundColor()),
            $this->firstColor($tiers, static fn (StyleSettings $s): ?string => $s->getButtonColor()),
            $this->firstBool($tiers, static fn (StyleSettings $s): ?bool => $s->getGlowEnabled()) ?? false,
        );
    }

    public function profileStyleFor(User $owner): ?StyleSettings
    {
        return $this->profiles->findOneBy(['user' => $owner])?->getStyle();
    }

    /**
     * @param array<array-key, StyleSettings|null> $tiers
     * @param callable(StyleSettings): ?string $get
     */
    private function firstColor(array $tiers, callable $get): ?string
    {
        foreach ($tiers as $tier) {
            if ($tier !== null) {
                $value = $get($tier);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<array-key, StyleSettings|null> $tiers
     * @param callable(StyleSettings): ?bool $get
     */
    private function firstBool(array $tiers, callable $get): ?bool
    {
        foreach ($tiers as $tier) {
            if ($tier !== null) {
                $value = $get($tier);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }
}
