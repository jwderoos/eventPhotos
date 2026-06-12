<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Event;
use DateTimeZone;
use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class EventDateExtension extends AbstractExtension
{
    /**
     * @return list<TwigFilter>
     */
    #[Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('event_date_in_tz', $this->eventDateInTz(...)),
        ];
    }

    public function eventDateInTz(Event $event): string
    {
        return $event->getStartsAt()
            ->setTimezone(new DateTimeZone($event->getTimezone()))
            ->format('Y-m-d');
    }
}
