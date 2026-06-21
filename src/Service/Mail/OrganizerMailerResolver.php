<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Event;
use App\Entity\User;
use App\Entity\UserMailConfig;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SodiumException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;

final readonly class OrganizerMailerResolver
{
    public function __construct(
        private DsnVault $vault,
        private MailerInterface $platformMailer,
        private LoggerInterface $logger,
        private PinnedTransportFactory $pinnedTransports,
        private EntityManagerInterface $em,
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

        try {
            return new Mailer($this->pinnedTransports->create($dsn));
        } catch (DsnRejected $dsnRejected) {
            if ($dsnRejected->reason === DsnRejected::REASON_HOST) {
                $this->logger->error(
                    'Auto-unverifying mail config: verified transport now resolves to a non-public address.',
                    ['user_id' => $user->getId(), 'reason' => $dsnRejected->getMessage()],
                );
                $config->revokeVerification();
                $this->em->flush();
            }

            throw $dsnRejected;
        }
    }

    public function isCustomActive(User $user): bool
    {
        $config = $user->getMailConfig();

        return $config instanceof UserMailConfig && $config->isVerified();
    }
}
