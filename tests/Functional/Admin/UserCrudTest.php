<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class UserCrudTest extends WebTestCase
{
    public function testAdminCanReachUserIndex(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Users');
    }

    public function testOrganizerIsForbiddenFromUserIndex(): void
    {
        $client = self::createClient();
        $org    = $this->seedUser('org@example.com', 'Org', ['ROLE_ORGANIZER']);
        $client->loginUser($org);

        $client->request(Request::METHOD_GET, '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexListsKnownUsers(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $this->seedUser('other@example.com', 'Other', ['ROLE_ORGANIZER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'admin@example.com');
        self::assertSelectorTextContains('table', 'other@example.com');
    }

    public function testAdminCanCreateUserAndResetEmailIsSent(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users/new');
        $client->submitForm('Create', [
            'user_create[email]'       => 'new.user@example.com',
            'user_create[displayName]' => 'New User',
            'user_create[role]'        => 'ROLE_ORGANIZER',
        ]);

        self::assertResponseRedirects('/admin/users');
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('new.user@example.com', $email->getTo()[0]->getAddress());

        $container = self::getContainer();
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $created = $users->findOneByEmail('new.user@example.com');
        $this->assertInstanceOf(User::class, $created);
        $this->assertContains('ROLE_ORGANIZER', $created->getRoles());
        $this->assertNotEmpty($created->getPassword(), 'random unusable hash should still occupy the column');
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $this->seedUser('taken@example.com', 'Taken', ['ROLE_USER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users/new');
        $client->submitForm('Create', [
            'user_create[email]'       => 'taken@example.com',
            'user_create[displayName]' => 'Dup',
            'user_create[role]'        => 'ROLE_ORGANIZER',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testAdminCanEditOtherUserDisplayNameAndRole(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $other  = $this->seedUser('other@example.com', 'Other', ['ROLE_USER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users/' . $other->getId() . '/edit');
        $client->submitForm('Save', [
            'user_edit[displayName]' => 'Renamed',
            'user_edit[role]'        => 'ROLE_ORGANIZER',
        ]);

        self::assertResponseRedirects('/admin/users');

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $reloaded = $users->findOneByEmail('other@example.com');
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertSame('Renamed', $reloaded->getDisplayName());
        $this->assertContains('ROLE_ORGANIZER', $reloaded->getRoles());
        // Note: $user->getRoles() auto-appends ROLE_USER; can't assert its absence here.
    }

    public function testAdminEditingSelfHasNoRoleField(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/admin/users/' . $admin->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('input[name="user_edit[role]"]'));
    }

    public function testAdminEditingSelfCanRenameSelfButNotChangeOwnRole(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users/' . $admin->getId() . '/edit');
        $client->submitForm('Save', [
            'user_edit[displayName]' => 'Renamed Admin',
        ]);
        self::assertResponseRedirects('/admin/users');

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $reloaded = $users->findOneByEmail('admin@example.com');
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertSame('Renamed Admin', $reloaded->getDisplayName());
        $this->assertContains('ROLE_ADMIN', $reloaded->getRoles(), 'self-edit must not be able to demote self');
    }

    public function testAdminCanDeleteUserWithNoOwnedContent(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $target = $this->seedUser('target@example.com', 'Target', ['ROLE_USER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_POST, '/admin/users/' . $target->getId() . '/delete', [
            '_token' => $this->csrf($client, 'delete_user_' . $target->getId()),
        ]);

        self::assertResponseRedirects('/admin/users');
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $this->assertNotInstanceOf(User::class, $users->findOneByEmail('target@example.com'));
    }

    public function testDeleteBlockedWhenUserOwnsEvents(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $owner  = $this->seedUser('owner@example.com', 'Owner', ['ROLE_ORGANIZER']);

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->persist(new Event(
            'o-1',
            'Owned',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        ));
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_POST, '/admin/users/' . $owner->getId() . '/delete', [
            '_token' => $this->csrf($client, 'delete_user_' . $owner->getId()),
        ]);

        self::assertResponseRedirects('/admin/users/' . $owner->getId() . '/edit');
        $em->clear();
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $this->assertInstanceOf(User::class, $users->findOneByEmail('owner@example.com'), 'user must still exist');
    }

    public function testAdminCannotDeleteSelf(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_POST, '/admin/users/' . $admin->getId() . '/delete', [
            '_token' => $this->csrf($client, 'delete_user_' . $admin->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanResendResetEmail(): void
    {
        $client = self::createClient();
        $admin  = $this->seedUser('admin@example.com', 'Admin', ['ROLE_ADMIN']);
        $target = $this->seedUser('target@example.com', 'Target', ['ROLE_ORGANIZER']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_POST, '/admin/users/' . $target->getId() . '/send-reset', [
            '_token' => $this->csrf($client, 'send_reset_' . $target->getId()),
        ]);

        self::assertResponseRedirects('/admin/users/' . $target->getId() . '/edit');
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('target@example.com', $email->getTo()[0]->getAddress());
    }

    private function csrf(KernelBrowser $client, string $id): string
    {
        // A GET request is needed to boot a session before writing a CSRF token into it.
        $client->request(Request::METHOD_GET, '/admin/users');

        $session = $client->getRequest()->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = bin2hex(random_bytes(16));
        $session->set(SessionTokenStorage::SESSION_NAMESPACE . '/' . $id, $token);
        $session->save();

        return $token;
    }

    /** @param list<string> $roles */
    private function seedUser(
        string $email,
        string $displayName,
        array $roles,
    ): User {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User($email, $displayName);
        foreach ($roles as $role) {
            $user->addRole($role);
        }

        $user->setPassword($hasher->hashPassword($user, 'placeholder placeholder'));
        $em->persist($user);
        $em->flush();
        return $user;
    }
}
