<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\Invitation;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use App\Enum\OAuthRefusalReason;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class IdentityCreator
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Creates a User + Google UserIdentity, marks the invite used. All within one transaction
     * with PESSIMISTIC_WRITE on the invite (mirrors password-invite redemption).
     *
     * Returns null if a concurrent caller already redeemed the invite (race-lost).
     *
     * @throws LoginRefused for EmailNotVerified / EmailTaken
     */
    public function createUserFromInvite(Invitation $invite, GoogleUserData $data): ?User
    {
        if (!$data->emailVerified) {
            throw new LoginRefused(OAuthRefusalReason::EmailNotVerified);
        }

        if ($this->users->findOneByEmail($data->email) instanceof User) {
            throw new LoginRefused(OAuthRefusalReason::EmailTaken);
        }

        $created = $this->em->wrapInTransaction(function () use ($invite, $data): ?User {
            $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($invite);

            if (!$invite->isPending()) {
                return null;
            }

            $user = new User($data->email, $data->displayName);
            $user->addRole($invite->getRole());
            $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));

            $identity = new UserIdentity($user, AuthProvider::Google, $data->subject, $data->email);
            $user->addIdentity($identity);

            $this->em->persist($user);
            $this->em->persist($identity);
            $this->em->flush();

            $invite->markUsed($user, $data->email);
            $this->em->flush();

            return $user;
        });

        return $created instanceof User ? $created : null;
    }
}
