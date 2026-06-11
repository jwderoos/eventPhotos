<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Invitation;
use App\Entity\User;
use App\Service\Invitation\InvitationTokenService;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InvitationConcurrencyTest extends KernelTestCase
{
    public function testTwoConcurrentRedemptionsProduceOneUser(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var InvitationTokenService $tokens */
        $tokens = $container->get(InvitationTokenService::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $admin = new User('concurrency-admin@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('hashed');

        $em->persist($admin);

        $gen = $tokens->generate();
        $invite = new Invitation(
            selector: $gen->selector,
            hashedVerifier: $gen->hashedVerifier,
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($invite);
        $em->flush();
        $em->clear();

        $inviteId = $invite->getId();
        $this->assertNotNull($inviteId);

        $redeem = function (string $email) use ($container, $hasher, $inviteId): ?int {
            /** @var EntityManagerInterface $em */
            $em = $container->get(EntityManagerInterface::class);
            $em->clear();

            return $em->wrapInTransaction(function () use ($em, $hasher, $inviteId, $email): ?int {
                $invite = $em->find(Invitation::class, $inviteId, LockMode::PESSIMISTIC_WRITE);
                if (!$invite instanceof Invitation || !$invite->isPending()) {
                    return null;
                }

                $user = new User($email, 'Concurrent');
                $user->addRole($invite->getRole());
                $user->setPassword($hasher->hashPassword($user, 'a-very-strong-passphrase'));

                $em->persist($user);
                $em->flush();

                $invite->markUsed($user, $email);
                $em->flush();

                return $user->getId();
            });
        };

        $userA = $redeem('concurrent-a@example.com');
        $userB = $redeem('concurrent-b@example.com');

        $winners = array_filter([$userA, $userB], static fn (?int $id): bool => $id !== null);
        $this->assertCount(1, $winners, 'Exactly one redemption should succeed.');

        // Cleanup so we don't pollute the dev DB.
        /** @var EntityManagerInterface $cleanupEm */
        $cleanupEm = $container->get(EntityManagerInterface::class);
        $cleanupEm->clear();

        $cleanupInvite = $cleanupEm->find(Invitation::class, $inviteId);
        if ($cleanupInvite instanceof Invitation) {
            $cleanupEm->remove($cleanupInvite);
        }

        foreach (['concurrent-a@example.com', 'concurrent-b@example.com', 'concurrency-admin@example.com'] as $email) {
            $u = $cleanupEm->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($u instanceof User) {
                $cleanupEm->remove($u);
            }
        }

        $cleanupEm->flush();
    }
}
