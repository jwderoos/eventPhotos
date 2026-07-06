<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminUserStyleTest extends WebTestCase
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

    public function testAdminCanSetTargetBrandAndStyleCreatingProfile(): void
    {
        $admin  = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('target@example.com', ['ROLE_ORGANIZER']);
        $targetId = $target->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/users/' . $targetId . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/style"]')->form();
        $form['organizer_profile[brandLabel]'] = 'Target Brand';
        $form['organizer_profile[brandUrl]']   = 'https://target.example';

        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users/' . $targetId . '/edit');

        $this->em->clear();
        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $targetId);
        $profile  = $this->em->getRepository(OrganizerProfile::class)->findOneBy(['user' => $reloaded]);

        $this->assertInstanceOf(OrganizerProfile::class, $profile);
        $this->assertSame('Target Brand', $profile->getBrandLabel());
        $this->assertSame('https://target.example', $profile->getBrandUrl());
    }

    public function testBrandLogoRouteReturns404WhenTargetHasNoLogo(): void
    {
        $admin  = $this->seedUser('admin2@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('target2@example.com', ['ROLE_ORGANIZER']);

        $this->client->loginUser($admin);
        $this->client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/brand-logo');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOrganizerIsForbiddenFromChangingAnotherUsersStyle(): void
    {
        $organizer = $this->seedUser('org@example.com', ['ROLE_ORGANIZER']);
        $target    = $this->seedUser('victim@example.com', ['ROLE_ORGANIZER']);

        $this->client->loginUser($organizer);
        $this->client->request(
            Request::METHOD_POST,
            '/admin/users/' . $target->getId() . '/style',
        );
        // /admin/** requires ROLE_ORGANIZER to enter, then UserVoter::EDIT denies non-admins.
        self::assertResponseStatusCodeSame(403);
    }

    public function testOrganizerIsForbiddenFromViewingAnotherUsersBrandLogo(): void
    {
        $organizer = $this->seedUser('org2@example.com', ['ROLE_ORGANIZER']);
        $target    = $this->seedUser('victim2@example.com', ['ROLE_ORGANIZER']);

        $this->client->loginUser($organizer);
        $this->client->request(
            Request::METHOD_GET,
            '/admin/users/' . $target->getId() . '/brand-logo',
        );
        self::assertResponseStatusCodeSame(403);
    }
}
