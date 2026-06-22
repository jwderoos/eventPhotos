<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Entity\UserMailConfigAudit;
use App\Enum\MailProvider;
use App\Tests\Mail\CapturedMail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserMailFlowTest extends WebTestCase
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

    public function testAdminCanConfigureMailForOtherUser(): void
    {
        $admin = $this->createUser('admin@example.com', 'ROLE_ADMIN');
        $target = $this->createUser('target@example.com', 'ROLE_ORGANIZER');
        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/mail');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[dsn]' => 'smtp://user:pass@smtp.example-organizer.test:25',
            'user_mail_config[fromAddr]' => 'target@example-organizer.test',
            'user_mail_config[fromName]' => 'Target',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users/' . $target->getId() . '/mail');

        $this->assertCount(1, CapturedMail::messagesForHost('93.184.216.34'));

        $this->em->clear();
        $audits = $this->em->getRepository(UserMailConfigAudit::class)->findAll();
        $this->assertCount(1, $audits);
        $audit = $audits[0];
        $this->assertSame($target->getEmail(), $audit->getUser()->getEmail());
        $this->assertInstanceOf(User::class, $audit->getActor());
        $this->assertSame($admin->getEmail(), $audit->getActor()->getEmail());
        $this->assertSame($admin->getEmail(), $audit->getActorEmailSnapshot());
    }

    public function testAdminCanConfigureGmailForOtherUser(): void
    {
        $admin = $this->createUser('admin-gmail@example.com', 'ROLE_ADMIN');
        $target = $this->createUser('target-gmail@example.com', 'ROLE_ORGANIZER');
        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/mail');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save and send verification')->form([
            'user_mail_config[provider]' => 'gmail',
            'user_mail_config[gmailEmail]' => 'target@gmail.com',
            'user_mail_config[gmailAppPassword]' => 'wxyz efgh ijkl mnop',
            'user_mail_config[fromAddr]' => '',
            'user_mail_config[fromName]' => '',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users/' . $target->getId() . '/mail');

        $this->assertCount(1, CapturedMail::messagesForHost('93.184.216.40'));

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($target->getId());
        $config = $reloaded?->getMailConfig();
        $this->assertInstanceOf(UserMailConfig::class, $config);
        $this->assertSame(MailProvider::Gmail, $config->getProvider());
        $this->assertSame('target@gmail.com', $config->getFromAddr());
    }

    public function testOrganizerCannotEditOtherUsersMail(): void
    {
        $organizer = $this->createUser('organizer@example.com', 'ROLE_ORGANIZER');
        $target = $this->createUser('victim@example.com', 'ROLE_ORGANIZER');
        $this->client->loginUser($organizer);

        $this->client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/mail');
        self::assertResponseStatusCodeSame(403);
    }

    private function createUser(string $email, string $role): User
    {
        $user = new User($email, 'Display');
        $user->addRole($role);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'secret'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
