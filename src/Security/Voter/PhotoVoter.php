<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Photo;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Photo>
 */
final class PhotoVoter extends Voter
{
    public const string EDIT   = 'PHOTO_EDIT';

    public const string DELETE = 'PHOTO_DELETE';

    public const string VIEW   = 'PHOTO_VIEW';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW], true)
            && $subject instanceof Photo;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        if (!$subject instanceof Photo) {
            return false;
        }

        return $subject->getEvent()->getOwner() === $user;
    }
}
