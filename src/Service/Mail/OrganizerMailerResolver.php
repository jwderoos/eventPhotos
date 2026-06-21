<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Event;
use App\Entity\User;
use App\Entity\UserMailConfig;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SodiumException;
use Symfony\Component\Mailer\MailerInterface;

final readonly class OrganizerMailerResolver
{
    public function __construct(
        private DsnVault $vault,
        private LoggerInterface $logger,
        private TransportBuilder $transportBuilder,
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
            throw new OrganizerMailNotConfiguredException(
                'Organizer has no verified mail transport.',
            );
        }

        try {
            $dsn = $this->vault->decrypt($config->getEncryptedDsn());
        } catch (SodiumException $sodiumException) {
            $this->logger->error(
                'Cannot decrypt mail config DSN for user; refusing to fall back to platform mail.',
                ['user_id' => $user->getId(), 'exception' => $sodiumException->getMessage()],
            );

            throw new OrganizerMailNotConfiguredException(
                'Stored mail transport ciphertext is corrupted.',
                0,
                $sodiumException,
            );
        }

        try {
            return $this->transportBuilder->fromDsn($dsn);
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
