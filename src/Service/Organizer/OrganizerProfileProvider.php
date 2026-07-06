<?php

declare(strict_types=1);

namespace App\Service\Organizer;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;

final readonly class OrganizerProfileProvider
{
    public function __construct(private OrganizerProfileRepository $profiles)
    {
    }

    public function loadOrCreate(User $user): OrganizerProfile
    {
        return $this->profiles->findOneBy(['user' => $user]) ?? new OrganizerProfile($user);
    }
}
