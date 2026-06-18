<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\UserMailConfig;
use App\Entity\User;
use App\Tests\Mail\CapturedMail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

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
        $messages = CapturedMail::messagesForHost('smtp.example-organizer.test');
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

    public function testVerificationEmailFailureKeepsConfigUnverified(): void
    {
        $user = $this->createOrganizer('fail@example.com', 'secret');
        $this->client->loginUser($user);

        CapturedMail::throwOnHost(
            'smtp.fail.example-organizer.test',
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
}
