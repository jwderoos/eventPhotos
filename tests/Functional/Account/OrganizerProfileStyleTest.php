<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class OrganizerProfileStyleTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    private function makeOrganizer(string $email = 'organizer@example.com'): User
    {
        $u = new User($email, 'Organizer');
        $u->addRole('ROLE_ORGANIZER');
        $u->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testOrganizerCanSetStyleDefaults(): void
    {
        $user = $this->makeOrganizer();
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();

        // Locate the style form by its action URL
        $form = $crawler->filter('form[action$="/account/style"]')->form();

        // Set the custom font color checkbox and color value
        $form['organizer_profile[style][customFontColor]'] = '1';
        $form['organizer_profile[style][fontColor]'] = '#222222';

        $this->client->submit($form);
        self::assertResponseRedirects('/account');

        $userId = $user->getId();
        $this->em->clear();

        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $userId);
        $this->assertInstanceOf(User::class, $reloaded);

        $profile = $this->em->getRepository(OrganizerProfile::class)->findOneBy(['user' => $reloaded]);
        $this->assertInstanceOf(OrganizerProfile::class, $profile);
        $this->assertSame('#222222', $profile->getStyle()->getFontColor());
    }

    public function testStyleFormIsVisibleOnAccountPage(): void
    {
        $user = $this->makeOrganizer('style-visible@example.com');
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/account/style"]');
        $this->assertStringContainsString('Branding defaults', (string) $this->client->getResponse()->getContent());
    }

    public function testSubmitUpdatesExistingProfile(): void
    {
        $user = $this->makeOrganizer('update-existing@example.com');

        // Pre-create a profile
        $profile = new OrganizerProfile($user);
        $this->em->persist($profile);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/account/style"]')->form();
        $form['organizer_profile[style][customFontColor]'] = '1';
        $form['organizer_profile[style][fontColor]'] = '#333333';

        $this->client->submit($form);
        self::assertResponseRedirects('/account');

        $profileId = $profile->getId();
        $this->em->clear();

        /** @var OrganizerProfile|null $reloaded */
        $reloaded = $this->em->find(OrganizerProfile::class, $profileId);
        $this->assertInstanceOf(OrganizerProfile::class, $reloaded);
        $this->assertSame('#333333', $reloaded->getStyle()->getFontColor());
    }
}
