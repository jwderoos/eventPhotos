<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Verifies that when GOOGLE_OAUTH_CLIENT_ID is empty:
 * - the /oauth/google/login route resolves to 404 (condition gate)
 * - the login page does not render the Google button
 *
 * Uses #[RunTestsInSeparateProcesses] so the env-var override is applied before the
 * Symfony kernel boots in each test process — this ensures the runtime env resolution
 * via $container->getEnv('default::GOOGLE_OAUTH_CLIENT_ID') sees the empty value.
 *
 * DAMA's transaction rollback relies on a static DB connection; that connection does not
 * cross process boundaries, so each test process commits its DB writes for real. To keep
 * the suite clean, tearDown deletes the seed row.
 */
#[RunTestsInSeparateProcesses]
final class FeatureFlagOffTest extends WebTestCase
{
    private ?User $seededUser = null;

    protected function setUp(): void
    {
        // Force the flag off for this test, regardless of phpunit.dist.xml defaults.
        $_ENV['GOOGLE_OAUTH_CLIENT_ID'] = '';
        $_SERVER['GOOGLE_OAUTH_CLIENT_ID'] = '';
        putenv('GOOGLE_OAUTH_CLIENT_ID=');
    }

    protected function tearDown(): void
    {
        if ($this->seededUser instanceof User) {
            /** @var EntityManagerInterface $em */
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $id = $this->seededUser->getId();
            if ($id !== null) {
                $user = $em->find(User::class, $id);
                if ($user !== null) {
                    $em->remove($user);
                    $em->flush();
                }
            }

            $this->seededUser = null;
        }

        parent::tearDown();
    }

    public function testGoogleRouteReturns404WhenFlagOff(): void
    {
        $client = self::createClient();
        $this->seedUser();
        $client->request(Request::METHOD_GET, '/oauth/google/login');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLoginPageHasNoGoogleButtonWhenFlagOff(): void
    {
        $client = self::createClient();
        $this->seedUser();
        $client->request(Request::METHOD_GET, '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="google-login"]');
    }

    /**
     * Seeds a minimal user so the FirstRunBootstrapSubscriber does not redirect to /setup.
     * The seeded user is tracked on $this->seededUser so tearDown can remove it.
     */
    private function seedUser(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $seed = new User(uniqid('flag-off-', true) . '@example.com', 'Seed');
        $seed->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $em->persist($seed);
        $em->flush();

        $this->seededUser = $seed;
    }
}
