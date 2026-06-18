<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\UserMailConfig;
use App\Entity\Event;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

final readonly class OrganizerMailerResolver
{
    public function __construct(
        private DsnVault $vault,
        private MailerInterface $platformMailer,
        private LoggerInterface $logger,
        #[Autowire(service: 'mailer.transport_factory')]
        private Transport $transports,
    ) {
    }

    public function forEvent(Event $event): MailerInterface
    {
        return $this->forUser($event->getOwner());
    }

    public function forUser(User $user): MailerInterface
    {
        $config = $user->getMailConfig();
        if (!$config instanceof UserMailConfig || !$config->isVerified()) {
            return $this->platformMailer;
        }

        try {
            $dsn = $this->vault->decrypt($config->getEncryptedDsn());
        } catch (SodiumException $sodiumException) {
            $this->logger->error(
                'Falling back to platform mailer: cannot decrypt mail config DSN for user.',
                ['user_id' => $user->getId(), 'exception' => $sodiumException->getMessage()],
            );
            return $this->platformMailer;
        }

        return new Mailer($this->transports->fromString($dsn));
    }

    public function isCustomActive(User $user): bool
    {
        $config = $user->getMailConfig();
        return $config instanceof UserMailConfig && $config->isVerified();
    }
}
