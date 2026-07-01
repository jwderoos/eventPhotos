<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Enum\MailProvider;
use App\Service\Mail\DsnVault;
use App\Service\Mail\OrganizerMailerResolver;
use App\Service\Mail\DsnRejected;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\UserMailConfig;
use App\Entity\User;
use App\Tests\Mail\CapturedMail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class AccountMailFlowTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
        CapturedMail::reset();
    }

    public function testFullVerifyCycle(): void
    {
        $user = $this->createOrganizer('flow@example.com', 'secret');
        $this->client->loginUser($user);

        // 1) GET edit page.
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
        self::assertResponseIsSuccessful();

        // 2) Submit a valid DSN through the custom transport.
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:pass@smtp.example-organizer.test:25',
            'user_mail_config[fromAddr]' => 'press@example-organizer.test',
            'user_mail_config[fromName]' => 'Example Press',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/account/mail');

        // 3) Verification email captured by the custom transport.
        $messages = CapturedMail::messagesForHost('93.184.216.34');
        $this->assertCount(1, $messages);

        // 4) Platform null transport NOT hit.
        $this->assertSame([], self::getMailerMessages());

        // 5) Extract verify URL from the message body. Strip quoted-printable
        // soft line breaks ("=\n") so long tokens reassemble cleanly.
        $body = (string) preg_replace('/=\r?\n/', '', $messages[0]->toString());
        $this->assertSame(
            1,
            preg_match('#http://localhost(/admin/account/mail/verify/[A-Za-z0-9_\-]+)#', $body, $m),
            'verify URL must appear in message body',
        );
        $verifyPath = $m[1];

        // 6) Click verify link → config marked verified.
        $this->client->request(Request::METHOD_GET, $verifyPath);
        self::assertResponseRedirects('/admin/account/mail');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $this->assertInstanceOf(User::class, $reloaded);
        $config = $reloaded->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $config);
        $this->assertTrue($config->isVerified());
    }

    public function testGmailModeAssemblesDsnAndDefaultsFromAddr(): void
    {
        $user = $this->createOrganizer('gmail-mode@example.com', 'secret');
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[provider]' => 'gmail',
            'user_mail_config[gmailEmail]' => 'organizer@gmail.com',
            'user_mail_config[gmailAppPassword]' => 'abcd efgh ijkl mnop',
            'user_mail_config[fromAddr]' => '',
            'user_mail_config[fromName]' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/account/mail');

        // Verification email went through the Gmail-shaped transport (stub IP for smtp.gmail.com).
        $this->assertCount(1, CapturedMail::messagesForHost('93.184.216.40'));

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $config = $reloaded?->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $config);
        $this->assertSame(MailProvider::Gmail, $config->getProvider());
        $this->assertSame('organizer@gmail.com', $config->getFromAddr());

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $dsn = $vault->decrypt($config->getEncryptedDsn());
        $this->assertSame(
            'smtps://organizer%40gmail.com:abcdefghijklmnop@smtp.gmail.com:465',
            $dsn,
        );
    }

    public function testGmailSpacedPasswordFromRealBrowserPost(): void
    {
        $user = $this->createOrganizer('spaced-pw@example.com', 'secret');
        $this->client->loginUser($user);

        // Mirror the exact POST Chrome sends: 16-char app password shown as four
        // space-separated groups. In form-urlencoded the spaces arrive as '+'/%20;
        // BrowserKit builds the body the same way the browser does.
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[provider]' => 'gmail',
            'user_mail_config[gmailEmail]' => 'trueskimmer@gmail.com',
            'user_mail_config[gmailAppPassword]' => 'ehga xrjk htgq ecfi',
            'user_mail_config[fromAddr]' => 'trueskimmer@gmail.com',
            'user_mail_config[fromName]' => 'Jan Willem',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/account/mail');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $config = $reloaded?->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $config);

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $dsn = $vault->decrypt($config->getEncryptedDsn());

        // The full 16-char password must survive — no dropped trailing character.
        $this->assertSame(
            'smtps://trueskimmer%40gmail.com:ehgaxrjkhtgqecfi@smtp.gmail.com:465',
            $dsn,
        );
    }

    public function testCustomModeStillWorks(): void
    {
        $user = $this->createOrganizer('still-custom@example.com', 'secret');
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[provider]' => 'custom',
            'user_mail_config[dsn]' => 'smtp://user:pass@smtp.example-organizer.test:25',
            'user_mail_config[fromAddr]' => 'press@example-organizer.test',
            'user_mail_config[fromName]' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/account/mail');

        $this->assertCount(1, CapturedMail::messagesForHost('93.184.216.34'));

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $config = $reloaded?->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $config);
        $this->assertSame(MailProvider::Custom, $config->getProvider());
    }

    private function createOrganizer(string $email, string $password): User
    {
        $user = new User($email, 'Flow');
        $user->addRole('ROLE_ORGANIZER');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testRejectsLoopbackHost(): void
    {
        $user = $this->createOrganizer('reject@example.com', 'secret');
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:pass@127.0.0.1:25',
            'user_mail_config[fromAddr]' => 'x@example.com',
            'user_mail_config[fromName]' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $this->assertSame([], CapturedMail::messagesForHost('127.0.0.1'));

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertNotInstanceOf(UserMailConfig::class, $reloaded->getMailConfig());
    }

    public function testCsrfRejectionOnClear(): void
    {
        $user = $this->createOrganizer('csrf@example.com', 'secret');
        $this->client->loginUser($user);
        $this->saveValidConfig($user);

        $this->client->request(Request::METHOD_POST, '/admin/account/mail/clear', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $this->assertInstanceOf(UserMailConfig::class, $reloaded?->getMailConfig());
    }

    public function testVerifyTokenExpired(): void
    {
        $user = $this->createOrganizer('expired@example.com', 'secret');
        $this->client->loginUser($user);
        $this->saveValidConfig($user);

        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "UPDATE user_mail_configs SET verification_sent_at = verification_sent_at - INTERVAL '25 hours'"
                . ' WHERE user_id = :uid',
            ['uid' => $user->getId()],
        );

        $token = $this->grabPendingToken($user);
        $this->client->request(Request::METHOD_GET, '/admin/account/mail/verify/' . $token);
        self::assertResponseRedirects('/admin/account/mail');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $config = $reloaded?->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $config);
        $this->assertFalse($config->isVerified());
    }

    public function testVerifyTokenOneShot(): void
    {
        $user = $this->createOrganizer('once@example.com', 'secret');
        $this->client->loginUser($user);
        $this->saveValidConfig($user);

        $token = $this->grabPendingToken($user);
        $this->client->request(Request::METHOD_GET, '/admin/account/mail/verify/' . $token);
        self::assertResponseRedirects('/admin/account/mail');

        $this->client->request(Request::METHOD_GET, '/admin/account/mail/verify/' . $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCrossUserVerifyForbidden(): void
    {
        $owner = $this->createOrganizer('owner@example.com', 'secret');
        $this->client->loginUser($owner);
        $this->saveValidConfig($owner);
        $token = $this->grabPendingToken($owner);

        $attacker = $this->createOrganizer('attacker@example.com', 'secret');
        $this->client->loginUser($attacker);

        $this->client->request(Request::METHOD_GET, '/admin/account/mail/verify/' . $token);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($owner->getId());
        $config = $reloaded?->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $config);
        $this->assertFalse($config->isVerified());
    }

    public function testFormRendersGmailFields(): void
    {
        $user = $this->createOrganizer('render@example.com', 'secret');
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
        self::assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('[data-controller="mail-provider"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('input[name="user_mail_config[gmailAppPassword]"]')->count());
    }

    private function saveValidConfig(User $user): void
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:pass@smtp.example-organizer.test:25',
            'user_mail_config[fromAddr]' => $user->getEmail(),
            'user_mail_config[fromName]' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/account/mail');
    }

    private function grabPendingToken(User $user): string
    {
        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $token = $reloaded?->getMailConfig()?->getVerificationToken();
        if ($token === null) {
            self::fail('No pending verification token for user.');
        }

        return $token;
    }

    /**
     * Issues a benign GET so the test client has an active session, then writes
     * a known CSRF token into that session under the fallback session-token namespace.
     */
    private function primeCsrfToken(string $tokenId): string
    {
        $this->client->request(Request::METHOD_GET, '/admin/account/mail');

        $session = $this->client->getRequest()->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = bin2hex(random_bytes(16));
        $session->set(SessionTokenStorage::SESSION_NAMESPACE . '/' . $tokenId, $token);
        $session->save();

        return $token;
    }

    public function testVerificationEmailFailureKeepsConfigUnverified(): void
    {
        $user = $this->createOrganizer('fail@example.com', 'secret');
        $this->client->loginUser($user);

        CapturedMail::throwOnHost(
            '93.184.216.35',
            new TransportException(
                'Authentication failed: 535 5.7.8 Username and Password not accepted',
            ),
        );

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/account/mail');
        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:bad@smtp.fail.example-organizer.test:25',
            'user_mail_config[fromAddr]' => 'press@example-organizer.test',
            'user_mail_config[fromName]' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/account/mail');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $config = $reloaded?->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $config);
        $this->assertFalse($config->isVerified());

        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Authentication failed', $body);
    }

    public function testLiveSendRefusesRebindToInternalHostAndAutoUnverifies(): void
    {
        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        /** @var OrganizerMailerResolver $resolver */
        $resolver = self::getContainer()->get(OrganizerMailerResolver::class);

        $user = $this->createOrganizer('rebind-fn@example.com', 'secret');
        $config = new UserMailConfig(
            $user,
            $vault->encrypt('smtp://u:p@box.loopback.rebind.example-organizer.test:25'),
            'rebind-fn@example.com',
            null,
        );
        $config->markVerified();

        $this->em->persist($config);
        $this->em->flush();

        $threw = false;
        try {
            $resolver->forUser($user);
        } catch (DsnRejected $dsnRejected) {
            $threw = true;
            $this->assertSame(DsnRejected::REASON_HOST, $dsnRejected->reason);
        }

        $this->assertTrue($threw, 'live send to a rebound internal host must be refused');
        $this->assertSame([], CapturedMail::messagesForHost('127.0.0.1'));

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $this->assertInstanceOf(User::class, $reloaded);
        $reloadedConfig = $reloaded->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $reloadedConfig);
        $this->assertFalse($reloadedConfig->isVerified());
    }

    public function testResendVerificationWithRebindHostIsGraceful(): void
    {
        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);

        // 1) Create an organizer and save a valid config so a UserMailConfig row exists.
        $user = $this->createOrganizer('resend-rebind@example.com', 'secret');
        $this->client->loginUser($user);
        $this->saveValidConfig($user);

        // 2) Mutate the stored DSN to a rebind host that resolves to 127.0.0.1.
        //    The domain box.loopback.rebind.example-organizer.test resolves to 127.0.0.1
        //    via the same DNS stub used by other tests in this suite.
        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $this->assertInstanceOf(User::class, $reloaded);
        $config = $reloaded->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $config);

        $rebindEnvelope = $vault->encrypt('smtp://u:p@box.loopback.rebind.example-organizer.test:25');
        $config->applyConfig($rebindEnvelope, $reloaded->getEmail(), null);
        $this->em->flush();

        // 3) POST to resend-verification with a valid CSRF token.
        $csrfToken = $this->primeCsrfToken('mail_config_resend');
        $this->client->request(
            Request::METHOD_POST,
            '/admin/account/mail/resend',
            ['_token' => $csrfToken],
        );

        // 4) Must be a graceful redirect (302), NOT a 500.
        self::assertResponseRedirects('/admin/account/mail', 302, 'DsnRejected at resend must not produce HTTP 500');

        // 5) No mail sent to the internal host.
        $this->assertSame([], CapturedMail::messagesForHost('127.0.0.1'));

        // 6) Follow redirect and assert a warning flash is present.
        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Could not send verification email', $body);
    }
}
