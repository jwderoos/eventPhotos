<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use Iterator;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountControllerTest extends WebTestCase
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

    protected function makeUser(
        string $email = 'me@example.com',
        string $hashed = '$2y$10$qqqqqqqqqqqqqqqqqqqqqq'
    ): User {
        $u = new User($email, 'Me');
        $u->addRole('ROLE_ORGANIZER');
        $u->setPassword($hashed);

        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        // Ensure at least one user exists so FirstRunBootstrapSubscriber does not redirect to /setup
        $this->makeUser('anon-test@example.com');
        $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseRedirects();
        $this->assertStringContainsString('/login', $this->client->getResponse()->headers->get('Location') ?? '');
    }

    public function testAuthenticatedUserSeesIdentitiesSection(): void
    {
        $this->client->loginUser($this->makeUser());
        $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Linked identities');
    }

    /**
     * Each standalone account form must render its own submit button. DomCrawler's
     * ->form() synthesizes submission without a button, so form-submit tests passed
     * while a real browser had nothing to click (regression guard for the missing
     * submit buttons on /account).
     *
     * @return Iterator<string, array{string}>
     */
    public static function accountFormActions(): Iterator
    {
        yield 'password' => ['/account/password'];
        yield 'display-name' => ['/account/display-name'];
        yield 'style' => ['/account/style'];
    }

    #[DataProvider('accountFormActions')]
    public function testEachAccountFormHasASubmitButton(string $actionSuffix): void
    {
        $this->client->loginUser($this->makeUser());
        $crawler = $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();

        $form    = $crawler->filter(sprintf('form[action$="%s"]', $actionSuffix));
        $submits = $form->filter('button[type="submit"], input[type="submit"]');

        $this->assertGreaterThan(
            0,
            $submits->count(),
            sprintf('form with action ending "%s" has no submit button', $actionSuffix),
        );
    }

    public function testChangePasswordRequiresCurrentWhenSet(): void
    {
        $c = self::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get('security.user_password_hasher');

        $u = new User('me@example.com', 'Me');
        $u->setPassword($hasher->hashPassword($u, 'oldpass!!'));
        $u->addRole('ROLE_ORGANIZER');

        $this->em->persist($u);
        $this->em->flush();

        $this->client->loginUser($u);
        $crawler = $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();

        // Locate the password-change form by its action URL
        $form = $crawler->filter('form[action$="/account/password"]')->form();
        $form['account_password_change[currentPassword]'] = 'oldpass!!';
        $form['account_password_change[newPassword][first]'] = 'newpass!!!';
        $form['account_password_change[newPassword][second]'] = 'newpass!!!';
        $this->client->submit($form);

        self::assertResponseRedirects('/account');

        $uid = $u->getId();
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $uid);
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertTrue($hasher->isPasswordValid($reloaded, 'newpass!!!'));
    }

    public function testChangeDisplayName(): void
    {
        $u = $this->makeUser();
        $this->client->loginUser($u);
        $crawler = $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/account/display-name"]')->form();
        $form['account_display_name[displayName]'] = 'Updated Name';
        $this->client->submit($form);

        self::assertResponseRedirects('/account');

        $uid = $u->getId();
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $uid);
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertSame('Updated Name', $reloaded->getDisplayName());
    }

    public function testUnlinkSucceedsForOwner(): void
    {
        $u = $this->makeUser();
        $identity = new UserIdentity($u, AuthProvider::Google, 'sub-123', 'me@example.com');
        $u->addIdentity($identity);
        $this->em->persist($identity);
        $this->em->flush();

        $identityId = $identity->getId();
        $this->assertNotNull($identityId);

        $this->client->loginUser($u);
        $crawler = $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();
        $tokenAttr = $crawler->filter('form[action$="/unlink"] input[name="_token"]')->attr('value');

        $this->client->request(
            Request::METHOD_POST,
            '/account/identities/' . $identityId . '/unlink',
            [
                '_token' => $tokenAttr,
            ]
        );
        self::assertResponseRedirects('/account');

        $this->em->clear();
        $this->assertNotInstanceOf(
            UserIdentity::class,
            $this->em->getRepository(UserIdentity::class)->find($identityId)
        );
    }

    public function testUnlinkRefusedForOtherUser(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $identity = new UserIdentity($owner, AuthProvider::Google, 'sub-x', 'owner@example.com');
        $owner->addIdentity($identity);
        $this->em->persist($identity);
        $this->em->flush();

        $identityId = $identity->getId();
        $this->assertNotNull($identityId);

        $attacker = $this->makeUser('attacker@example.com');
        $this->client->loginUser($attacker);
        $this->client->request(
            Request::METHOD_POST,
            '/account/identities/' . $identityId . '/unlink',
            [
                '_token' => 'bogus',
            ]
        );
        self::assertResponseStatusCodeSame(403);
    }
}
