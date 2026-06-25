<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Audit\AuditAction;
use App\Entity\AuditLogEntry;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\AuditLogEntryRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

abstract class AuditWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    protected function makeUser(string $email, string $role): User
    {
        $user = new User($email, ucfirst(strtok($email, '@') ?: 'User'));
        $user->addRole($role);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function loginAdmin(string $email = 'admin@example.com'): User
    {
        $admin = $this->makeUser($email, 'ROLE_ADMIN');
        $this->client->loginUser($admin);

        return $admin;
    }

    protected function loginOrganizer(string $email = 'organizer@example.com'): User
    {
        $organizer = $this->makeUser($email, 'ROLE_ORGANIZER');
        $this->client->loginUser($organizer);

        return $organizer;
    }

    protected function makeEvent(string $slug, User $owner, string $name = 'Hike 2026'): Event
    {
        $event = new Event(
            slug: $slug,
            name: $name,
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    /** Mirrors EventPublishTest::primeCsrfToken — writes a known token into the session. */
    protected function primeCsrfToken(string $tokenId): string
    {
        $this->client->request(Request::METHOD_GET, '/admin/events');

        $session = $this->client->getRequest()->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = bin2hex(random_bytes(16));
        $session->set(SessionTokenStorage::SESSION_NAMESPACE . '/' . $tokenId, $token);
        $session->save();

        return $token;
    }

    /** @return list<AuditLogEntry> */
    protected function auditRows(AuditAction $action): array
    {
        $this->em->clear();
        /** @var AuditLogEntryRepository $repo */
        $repo = self::getContainer()->get(AuditLogEntryRepository::class);

        return $repo->findBy(['action' => $action]);
    }
}
