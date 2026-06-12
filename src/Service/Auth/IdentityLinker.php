<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use App\Enum\OAuthRefusalReason;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class IdentityLinker
{
    public function __construct(
        private UserIdentityRepository $identities,
        private UserRepository $users,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws LoginRefused
     */
    public function resolveLogin(GoogleUserData $data): LoginResult
    {
        if (!$data->emailVerified) {
            throw new LoginRefused(OAuthRefusalReason::EmailNotVerified);
        }

        $identity = $this->identities->findBySubject(AuthProvider::Google, $data->subject);
        if ($identity instanceof UserIdentity) {
            return new LoginResult($identity->getUser(), wasAutoLinked: false);
        }

        $user = $this->users->findOneByEmail($data->email);
        if (!$user instanceof User) {
            throw new LoginRefused(OAuthRefusalReason::NoAccount);
        }

        if ($user->hasIdentityFor(AuthProvider::Google)) {
            throw new LoginRefused(OAuthRefusalReason::EmailBoundToOtherGoogle);
        }

        $newIdentity = new UserIdentity($user, AuthProvider::Google, $data->subject, $data->email);
        $user->addIdentity($newIdentity);
        $this->em->persist($newIdentity);
        $this->em->flush();

        return new LoginResult($user, wasAutoLinked: true);
    }

    /**
     * @throws LinkRefused
     */
    public function linkToCurrentUser(User $current, GoogleUserData $data): UserIdentity
    {
        if (!$data->emailVerified) {
            throw new LinkRefused(OAuthRefusalReason::EmailNotVerified);
        }

        if ($current->hasIdentityFor(AuthProvider::Google)) {
            throw new LinkRefused(OAuthRefusalReason::AlreadyLinkedToCurrent);
        }

        if ($this->identities->findBySubject(AuthProvider::Google, $data->subject) instanceof UserIdentity) {
            throw new LinkRefused(OAuthRefusalReason::BoundToOtherUser);
        }

        $identity = new UserIdentity($current, AuthProvider::Google, $data->subject, $data->email);
        $current->addIdentity($identity);
        $this->em->persist($identity);
        $this->em->flush();

        return $identity;
    }
}
