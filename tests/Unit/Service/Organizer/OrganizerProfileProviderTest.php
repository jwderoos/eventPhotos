<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Organizer;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;
use App\Service\Organizer\OrganizerProfileProvider;
use PHPUnit\Framework\TestCase;

final class OrganizerProfileProviderTest extends TestCase
{
    public function testReturnsExistingProfileWhenPresent(): void
    {
        $user = new User('has-profile@example.com', 'Has Profile');
        $existing = new OrganizerProfile($user);

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $provider = new OrganizerProfileProvider($repo);

        $this->assertSame($existing, $provider->loadOrCreate($user));
    }

    public function testReturnsNewUnpersistedProfileWhenAbsent(): void
    {
        $user = new User('no-profile@example.com', 'No Profile');

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $provider = new OrganizerProfileProvider($repo);

        $profile = $provider->loadOrCreate($user);

        $this->assertNull($profile->getId());
        $this->assertSame($user, $profile->getUser());
    }
}
