<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Event;
use App\Service\EventSlugGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::prePersist, entity: Event::class)]
final readonly class EventSlugListener
{
    public function __construct(private EventSlugGenerator $generator)
    {
    }

    public function prePersist(Event $event): void
    {
        if ($event->getSlug() === '') {
            $event->setSlug($this->generator->generate($event->getName()));
        }
    }
}
