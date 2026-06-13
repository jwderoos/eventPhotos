<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Entity\Event;
use DateTimeImmutable;

final class IngestWindowGuard
{
    public const int GRACE_MINUTES = 30;

    public function assertWithinWindow(Event $event, DateTimeImmutable $takenAt): void
    {
        $lower = $event->getStartsAt()->modify('-' . self::GRACE_MINUTES . ' minutes');
        $upper = $event->getEndsAt()->modify('+' . self::GRACE_MINUTES . ' minutes');

        if ($takenAt < $lower || $takenAt > $upper) {
            throw new PhotoRejected(sprintf(
                'Photo timestamp %s UTC is outside the event window %s..%s UTC (±%d min grace).',
                $takenAt->format('Y-m-d H:i:s'),
                $event->getStartsAt()->format('Y-m-d H:i'),
                $event->getEndsAt()->format('Y-m-d H:i'),
                self::GRACE_MINUTES,
            ));
        }
    }
}
