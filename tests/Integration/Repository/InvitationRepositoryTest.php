<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Invitation;
use App\Entity\User;
use App\Repository\InvitationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvitationRepositoryTest extends KernelTestCase
{
    public function testFindBySelectorReturnsMatch(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var InvitationRepository $repo */
        $repo = $container->get(InvitationRepository::class);

        $admin = new User('admin-repo@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('hashed');

        $em->persist($admin);

        $selector = str_repeat('a', 32);
        $invite = new Invitation(
            selector: $selector,
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($invite);
        $em->flush();

        $found = $repo->findBySelector($selector);
        $this->assertInstanceOf(Invitation::class, $found);
        $this->assertSame($invite->getId(), $found->getId());

        $this->assertNotInstanceOf(Invitation::class, $repo->findBySelector(str_repeat('z', 32)));
    }

    public function testFindAllOrderedByCreatedReturnsNewestFirst(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var InvitationRepository $repo */
        $repo = $container->get(InvitationRepository::class);

        $admin = new User('admin-ordering@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('hashed');

        $em->persist($admin);

        $older = new Invitation(
            selector: str_repeat('1', 32),
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($older);
        $em->flush();

        // Force a different createdAt
        usleep(1_100_000);

        $newer = new Invitation(
            selector: str_repeat('2', 32),
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($newer);
        $em->flush();

        $all = $repo->findAllOrderedByCreated();

        $ids = array_map(static fn (Invitation $i): ?int => $i->getId(), $all);
        $newerIdx = array_search($newer->getId(), $ids, true);
        $olderIdx = array_search($older->getId(), $ids, true);
        $this->assertIsInt($newerIdx);
        $this->assertIsInt($olderIdx);
        $this->assertLessThan($olderIdx, $newerIdx, 'Newer invitation should come before older.');
    }
}
