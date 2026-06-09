<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EventCollection;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, EventCollection>
 */
final class EventCollectionVoter extends Voter
{
    public const string EDIT   = 'COLLECTION_EDIT';

    public const string DELETE = 'COLLECTION_DELETE';

    public const string VIEW   = 'COLLECTION_VIEW';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW], true)
            && $subject instanceof EventCollection;
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

        if (!$subject instanceof EventCollection) {
            return false;
        }

        return $subject->getOwner() === $user;
    }
}
