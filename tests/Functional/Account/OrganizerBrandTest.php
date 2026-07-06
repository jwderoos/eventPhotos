<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class OrganizerBrandTest extends WebTestCase
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

    private function makeOrganizer(string $email): User
    {
        $u = new User($email, 'Organizer');
        $u->addRole('ROLE_ORGANIZER');
        $u->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testOrganizerCanSetBrandLabelAndUrl(): void
    {
        $user = $this->makeOrganizer('brand-set@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/account/style"]')->form();
        $form['organizer_profile[brandLabel]'] = 'Acme Corp';
        $form['organizer_profile[brandUrl]'] = 'https://acme.example';

        $this->client->submit($form);
        self::assertResponseRedirects('/account');

        $userId = $user->getId();
        $this->em->clear();
        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $userId);
        $profile = $this->em->getRepository(OrganizerProfile::class)->findOneBy(['user' => $reloaded]);

        $this->assertInstanceOf(OrganizerProfile::class, $profile);
        $this->assertSame('Acme Corp', $profile->getBrandLabel());
        $this->assertSame('https://acme.example', $profile->getBrandUrl());
        $this->assertTrue($profile->hasBrand());
    }

    public function testBrandFieldsVisibleOnAccountPage(): void
    {
        $user = $this->makeOrganizer('brand-visible@example.com');
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="organizer_profile[brandLabel]"]');
        self::assertSelectorExists('input[name="organizer_profile[brandUrl]"]');
    }

    public function testBrandLogoPreviewRouteReturns404WhenUnset(): void
    {
        $user = $this->makeOrganizer('brand-nopreview@example.com');
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/account/brand-logo');
        self::assertResponseStatusCodeSame(404);
    }
}
