<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class AdminUserIdentityTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    /** @param list<string> $roles */
    private function seedUser(string $email, array $roles): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User($email, 'Seeded');
        foreach ($roles as $role) {
            $user->addRole($role);
        }

        $user->setPassword($hasher->hashPassword($user, 'placeholder placeholder'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seedIdentity(User $user, string $subject): UserIdentity
    {
        $identity = new UserIdentity($user, AuthProvider::Google, $subject, $user->getEmail());
        $user->addIdentity($identity);
        $this->em->persist($identity);
        $this->em->flush();

        return $identity;
    }

    private function csrf(string $id): string
    {
        // A GET request is needed to boot a session before writing a CSRF token into it.
        $this->client->request(Request::METHOD_GET, '/admin/users');

        $session = $this->client->getRequest()->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = bin2hex(random_bytes(16));
        $session->set(SessionTokenStorage::SESSION_NAMESPACE . '/' . $id, $token);
        $session->save();

        return $token;
    }

    public function testAdminCanUnlinkTargetIdentity(): void
    {
        $admin  = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('target@example.com', ['ROLE_ORGANIZER']);
        $identity = $this->seedIdentity($target, 'sub-admin-unlink');
        $identityId = $identity->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'target@example.com');

        $form = $crawler->filter('form[action$="/identities/' . $identityId . '/unlink"] button')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users/' . $target->getId() . '/edit');

        $this->em->clear();
        $this->assertNotInstanceOf(UserIdentity::class, $this->em->find(UserIdentity::class, $identityId));
    }

    public function testUnlinkReturns404WhenIdentityBelongsToDifferentUser(): void
    {
        $admin  = $this->seedUser('admin2@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('target2@example.com', ['ROLE_ORGANIZER']);
        $other  = $this->seedUser('other2@example.com', ['ROLE_ORGANIZER']);
        $identity = $this->seedIdentity($other, 'sub-cross-user');

        $this->client->loginUser($admin);

        // identity belongs to $other but URL names $target — must 404, not unlink.
        $token = $this->csrf('admin-unlink-identity-' . $identity->getId());

        $this->client->request(
            Request::METHOD_POST,
            '/admin/users/' . $target->getId() . '/identities/' . $identity->getId() . '/unlink',
            ['_token' => $token],
        );
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        $this->assertInstanceOf(UserIdentity::class, $this->em->find(UserIdentity::class, $identity->getId()));
    }

    public function testOrganizerIsForbiddenFromUnlinkingAnotherUsersIdentity(): void
    {
        $organizer = $this->seedUser('org@example.com', ['ROLE_ORGANIZER']);
        $target    = $this->seedUser('victim@example.com', ['ROLE_ORGANIZER']);
        $identity  = $this->seedIdentity($target, 'sub-forbidden');

        $this->client->loginUser($organizer);

        $token = $this->csrf('admin-unlink-identity-' . $identity->getId());

        $this->client->request(
            Request::METHOD_POST,
            '/admin/users/' . $target->getId() . '/identities/' . $identity->getId() . '/unlink',
            ['_token' => $token],
        );
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $this->assertInstanceOf(UserIdentity::class, $this->em->find(UserIdentity::class, $identity->getId()));
    }
}
