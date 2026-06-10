<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetTest extends WebTestCase
{
    public function testRequestForExistingUserSendsEmailAndShowsCheckEmailPage(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('alice@example.com', 'Alice');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'old password old'));

        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/reset-password');
        $client->submitForm('Send reset link', [
            'request_password_reset_form[email]' => 'alice@example.com',
        ]);

        self::assertResponseRedirects('/reset-password/check-email');

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('alice@example.com', $email->getTo()[0]->getAddress());

        $client->followRedirect();
        self::assertSelectorTextContains('[data-testid="reset-check-email"]', "we've sent a password reset link");
    }

    public function testRequestForUnknownUserShowsSamePageWithoutSendingEmail(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, '/reset-password');
        $client->submitForm('Send reset link', [
            'request_password_reset_form[email]' => 'nobody@example.com',
        ]);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertEmailCount(0);
        $this->assertCount(0, self::getMailerMessages());
    }

    public function testFullHappyPathRequestEmailClickResetAndLogIn(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('bob@example.com', 'Bob');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'original password 12'));

        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/reset-password');
        $client->submitForm('Send reset link', [
            'request_password_reset_form[email]' => 'bob@example.com',
        ]);

        self::assertEmailCount(1);
        $message = self::getMailerMessage(0);
        $this->assertInstanceOf(Email::class, $message);
        $resetLink = $this->extractFirstUrl((string) $message->getHtmlBody());
        $this->assertNotNull($resetLink, 'reset link must be present in email body');

        $client->request(Request::METHOD_GET, $resetLink);
        self::assertResponseRedirects();
        $client->followRedirect();

        $client->submitForm('Update password', [
            'change_password_form[plainPassword][first]'  => 'a brand new password',
            'change_password_form[plainPassword][second]' => 'a brand new password',
        ]);
        self::assertResponseRedirects('/login');

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            '_username' => 'bob@example.com',
            '_password' => 'a brand new password',
        ]);
        self::assertResponseRedirects('/admin');
    }

    public function testReusedTokenIsRejected(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('carol@example.com', 'Carol');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'original password 12'));

        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/reset-password');
        $client->submitForm('Send reset link', [
            'request_password_reset_form[email]' => 'carol@example.com',
        ]);

        self::assertEmailCount(1);
        $message = self::getMailerMessage(0);
        $this->assertInstanceOf(Email::class, $message);
        $resetLink = $this->extractFirstUrl((string) $message->getHtmlBody());
        $this->assertNotNull($resetLink);

        // First redemption — success.
        $client->request(Request::METHOD_GET, $resetLink);
        $client->followRedirect();
        $client->submitForm('Update password', [
            'change_password_form[plainPassword][first]'  => 'first new password!',
            'change_password_form[plainPassword][second]' => 'first new password!',
        ]);
        self::assertResponseRedirects('/login');

        // Second attempt with the same link — bundle deletes the row on success,
        // so token lookup fails and the user is sent back to /reset-password.
        $client->restart(); // fresh session (cookies + history) on the same kernel
        $client->request(Request::METHOD_GET, $resetLink);
        // First hop: store token in session, redirect to /reset-password/reset.
        $client->followRedirect();
        // Second hop: validateTokenAndFetchUser fails → flash + redirect to /reset-password.
        self::assertResponseRedirects('/reset-password');
    }

    public function testInvalidTokenIsRejected(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/reset-password/reset/this-is-not-a-valid-token-format-at-all');
        self::assertResponseRedirects();
        $client->followRedirect();
        // After following the session-store redirect, the validate call fails and we land back on /reset-password.
        self::assertResponseRedirects('/reset-password');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('dave@example.com', 'Dave');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'original password 12'));

        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/reset-password');
        $client->submitForm('Send reset link', [
            'request_password_reset_form[email]' => 'dave@example.com',
        ]);

        self::assertEmailCount(1);
        $message = self::getMailerMessage(0);
        $this->assertInstanceOf(Email::class, $message);
        $resetLink = $this->extractFirstUrl((string) $message->getHtmlBody());
        $this->assertNotNull($resetLink);

        // Force the underlying request row to be expired.
        $conn = $em->getConnection();
        $conn->executeStatement('UPDATE reset_password_request SET expires_at = :past', [
            'past' => '2000-01-01 00:00:00',
        ]);

        $client->request(Request::METHOD_GET, $resetLink);
        $client->followRedirect();
        self::assertResponseRedirects('/reset-password');
    }

    public function testSecondRequestWithinThrottleWindowDoesNotSendSecondEmail(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('erin@example.com', 'Erin');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'original password 12'));

        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/reset-password');
        $client->submitForm('Send reset link', [
            'request_password_reset_form[email]' => 'erin@example.com',
        ]);
        self::assertEmailCount(1);

        $client->request(Request::METHOD_GET, '/reset-password');
        $client->submitForm('Send reset link', [
            'request_password_reset_form[email]' => 'erin@example.com',
        ]);
        self::assertResponseRedirects('/reset-password/check-email');
        // The mailer message logger resets between requests, so the second submit's
        // own request context must show zero emails sent (throttle short-circuited the send).
        self::assertEmailCount(0);
    }

    private function extractFirstUrl(string $html): ?string
    {
        if (preg_match('#href="([^"]+/reset-password/reset/[^"]+)"#', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
