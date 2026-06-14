<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use Symfony\Component\HttpFoundation\Cookie;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Guards the 30-day rolling-cookie behavior driven by framework.session.cookie_lifetime.
 *
 * Note on approach: the test env uses MockFileSessionStorage, which still feeds the
 * configured cookie_lifetime through Symfony's AbstractSessionListener when it sets
 * the response cookie. A bare GET to /login does not populate the session (no token
 * is written until the form is submitted), so the listener would not emit a cookie.
 * Submitting valid credentials makes the firewall write _security_main into the
 * session, which triggers the listener to attach the cookie with the configured
 * lifetime — exactly the value we want to assert on.
 */
final class RollingCookieTest extends WebTestCase
{
    private const int THIRTY_DAYS_SECONDS = 2_592_000;

    private const int TOLERANCE_SECONDS = 60;

    public function testSuccessfulLoginIssuesSessionCookieWithThirtyDayLifetime(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('rolling-cookie@example.com', 'RollingCookie');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'correct horse battery'));

        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            '_username' => 'rolling-cookie@example.com',
            '_password' => 'correct horse battery',
        ]);

        self::assertResponseRedirects(
            '/admin',
            null,
            'Login should succeed and redirect to /admin so the firewall writes _security_main into the session.'
        );

        $cookie = $client->getResponse()->headers->getCookies()[0] ?? null;
        $this->assertInstanceOf(Cookie::class, $cookie, 'Expected the login response to issue a session cookie.');

        $expiresAt = $cookie->getExpiresTime();
        $this->assertGreaterThan(0, $expiresAt, 'Cookie should have a non-zero expiry, not be a session cookie.');

        $delta = $expiresAt - time();
        $this->assertEqualsWithDelta(
            self::THIRTY_DAYS_SECONDS,
            $delta,
            self::TOLERANCE_SECONDS,
            'Cookie expiry should be ~30 days out.'
        );
    }
}
