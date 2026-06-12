<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class PhotosUrlBuilder
{
    private const string TIME_FORMAT = 'H:i';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function build(Event $event, DateTimeImmutable $when, bool $absolute = false): string
    {
        return $this->urlGenerator->generate(
            'public_event_photos',
            [
                'slug' => $event->getSlug(),
                't'    => $when->format(self::TIME_FORMAT),
            ],
            $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
