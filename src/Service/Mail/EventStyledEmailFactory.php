<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Event;
use App\Service\Style\StyleResolver;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

final readonly class EventStyledEmailFactory
{
    private const string HERO_NAME = 'event-hero';

    private const string HERO_MIME = 'image/jpeg';

    public function __construct(
        private StyleResolver $styleResolver,
        #[Autowire(service: 'event_banners_storage')]
        private FilesystemOperator $banners,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function create(Event $event, string $htmlTemplate, string $textTemplate, array $context): TemplatedEmail
    {
        $email = new TemplatedEmail()
            ->htmlTemplate($htmlTemplate)
            ->textTemplate($textTemplate);

        $context['style'] = $this->styleResolver->resolve($event);

        $heroCid = $this->attachHero($event, $email);
        if ($heroCid !== null) {
            $context['heroCid'] = $heroCid;
        }

        $email->context($context);

        return $email;
    }

    private function attachHero(Event $event, TemplatedEmail $email): ?string
    {
        $filename = $event->getBannerFilename();
        if ($filename === null) {
            return null;
        }

        try {
            $bytes = $this->banners->read($filename);
        } catch (Throwable $throwable) {
            $this->logger->warning('Could not read event banner for email hero; sending without it.', [
                'event_id' => $event->getId(),
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }

        $hero = new DataPart($bytes, self::HERO_NAME, self::HERO_MIME)->asInline();
        $email->addPart($hero);

        return $hero->getContentId();
    }
}
