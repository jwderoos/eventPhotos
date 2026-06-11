<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, User>
 */
final class UserVoter extends Voter
{
    public const string VIEW      = 'USER_VIEW';

    public const string EDIT      = 'USER_EDIT';

    public const string EDIT_ROLE = 'USER_EDIT_ROLE';

    public const string DELETE    = 'USER_DELETE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::EDIT_ROLE, self::DELETE], true)
            && $subject instanceof User;
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

        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return false;
        }

        if (!$subject instanceof User) {
            return false;
        }

        if ($attribute === self::EDIT_ROLE || $attribute === self::DELETE) {
            return $subject->getId() !== $current->getId();
        }

        return true;
    }
}
