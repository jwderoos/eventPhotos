<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\UserIdentity;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, UserIdentity>
 */
final class UserIdentityVoter extends Voter
{
    public const string UNLINK = 'IDENTITY_UNLINK';

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::UNLINK && $subject instanceof UserIdentity;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $current = $token->getUser();
        if (!$current instanceof User) {
            return false;
        }

        if ($this->decisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        if (!$subject instanceof UserIdentity) {
            return false;
        }

        return $subject->getUser() === $current;
    }
}
