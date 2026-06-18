<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Mail;

use App\Entity\Event;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\DsnVault;
use App\Service\Mail\OrganizerMailerResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;

final class OrganizerMailerResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private OrganizerMailerResolver $resolver;

    private DsnVault $vault;

    private MailerInterface $platformMailer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var OrganizerMailerResolver $resolver */
        $resolver = $container->get(OrganizerMailerResolver::class);
        /** @var DsnVault $vault */
        $vault = $container->get(DsnVault::class);
        /** @var MailerInterface $platformMailer */
        $platformMailer = $container->get(MailerInterface::class);
        $this->em = $em;
        $this->resolver = $resolver;
        $this->vault = $vault;
        $this->platformMailer = $platformMailer;
    }

    public function testReturnsPlatformMailerWhenUserHasNoConfig(): void
    {
        $user = $this->persistUser('no-config@example.com');

        $this->assertSame($this->platformMailer, $this->resolver->forUser($user));
    }

    public function testReturnsPlatformMailerWhenConfigIsUnverified(): void
    {
        $user = $this->persistUser('pending@example.com');
        $config = new UserMailConfig(
            $user,
            $this->vault->encrypt('smtp://x@smtp.example.test:25'),
            'pending@example.com',
            null,
        );
        $this->em->persist($config);
        $this->em->flush();

        $this->assertSame($this->platformMailer, $this->resolver->forUser($user));
    }

    public function testReturnsCustomMailerWhenConfigIsVerified(): void
    {
        $user = $this->persistUser('verified@example.com');
        $config = new UserMailConfig(
            $user,
            $this->vault->encrypt('smtp://x@smtp.example.test:25'),
            'verified@example.com',
            null,
        );
        $config->markVerified();

        $this->em->persist($config);
        $this->em->flush();

        $resolved = $this->resolver->forUser($user);

        $this->assertNotSame($this->platformMailer, $resolved);
        $this->assertInstanceOf(Mailer::class, $resolved);
    }

    public function testForEventDelegatesToOwner(): void
    {
        $owner = $this->persistUser('event-owner@example.com');
        $config = new UserMailConfig(
            $owner,
            $this->vault->encrypt('smtp://x@smtp.example.test:25'),
            'event-owner@example.com',
            null,
        );
        $config->markVerified();

        $this->em->persist($config);

        $event = new Event(
            slug: 'sample-event',
            name: 'Sample Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00'),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00'),
            owner: $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->assertNotSame($this->platformMailer, $this->resolver->forEvent($event));
    }

    public function testIsCustomActive(): void
    {
        $u1 = $this->persistUser('a@example.com');
        $u2 = $this->persistUser('b@example.com');
        $config = new UserMailConfig(
            $u2,
            $this->vault->encrypt('smtp://x@smtp.example.test:25'),
            'b@example.com',
            null,
        );
        $config->markVerified();

        $this->em->persist($config);
        $this->em->flush();

        $this->assertFalse($this->resolver->isCustomActive($u1));
        $this->assertTrue($this->resolver->isCustomActive($u2));
    }

    private function persistUser(string $email): User
    {
        $user = new User($email, 'Display');
        $user->addRole('ROLE_ORGANIZER');

        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
