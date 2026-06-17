<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionControllerLabelTest extends WebTestCase
{
    public function testSetsAndClearsLabel(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $repo = $client->getContainer()->get(UserSessionRepository::class);
        $this->assertInstanceOf(UserSessionRepository::class, $repo);
        $user = $this->makeUser($em);
        $client->loginUser($user);

        $sessId = 'label_' . bin2hex(random_bytes(8));
        $em->persist(new UserSession($sessId, $user, '8.8.8.8', 'ua', null, null, new DateTimeImmutable()));
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/account/sessions');
        // Get the label form's token — use a selector that scopes to the row's label form.
        $token = $crawler->filter('form[action$="/' . $sessId . '/label"] input[name=_token]')->attr('value');

        // Set label.
        $client->request(Request::METHOD_POST, '/account/sessions/' . $sessId . '/label', [
            '_token' => $token,
            'label' => '  Work laptop  ',
        ]);
        self::assertResponseRedirects('/account/sessions');

        $em->clear();
        $row = $repo->findOneBySessId($sessId);
        $this->assertInstanceOf(UserSession::class, $row);
        $this->assertSame('Work laptop', $row->getLabel());

        // Clear label by submitting empty string.
        $client->request(Request::METHOD_POST, '/account/sessions/' . $sessId . '/label', [
            '_token' => $token,
            'label' => '',
        ]);

        $em->clear();
        $row = $repo->findOneBySessId($sessId);
        $this->assertInstanceOf(UserSession::class, $row);
        $this->assertNull($row->getLabel());
    }

    public function testRejectsLabelOverSixtyFourChars(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $user = $this->makeUser($em);
        $client->loginUser($user);

        $sessId = 'label_long_' . bin2hex(random_bytes(8));
        $em->persist(new UserSession($sessId, $user, '8.8.8.8', 'ua', null, null, new DateTimeImmutable()));
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/account/sessions');
        $token = $crawler->filter('form[action$="/' . $sessId . '/label"] input[name=_token]')->attr('value');

        $client->request(Request::METHOD_POST, '/account/sessions/' . $sessId . '/label', [
            '_token' => $token,
            'label' => str_repeat('A', 65),
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    private function makeUser(EntityManagerInterface $em): User
    {
        $user = new User(
            'label-test-' . bin2hex(random_bytes(4)) . '@example.com',
            'Label Test',
        );
        $user->setPassword('x');
        $user->addRole('ROLE_ORGANIZER');

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
