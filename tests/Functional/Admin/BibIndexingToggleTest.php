<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use Symfony\Component\HttpFoundation\Response;
use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BibIndexingToggleTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        /** @var EntityManagerInterface $em */
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    /** @return array{0: User, 1: Event} */
    private function makeOrganizerWithEvent(): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('owner@example.test', 'Owner');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'secret'));

        $this->em->persist($user);

        $event = new Event(
            'bib-run',
            'Bib Run',
            new DateTimeImmutable('2026-05-01 09:00'),
            new DateTimeImmutable('2026-05-01 12:00'),
            $user,
        );
        $this->em->persist($event);
        $this->em->flush();

        return [$user, $event];
    }

    private function submitEdit(Event $event, bool $enable, bool $attest): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/edit');
        $form    = $crawler->selectButton('Save')->form();

        $enabledField = $form['event[bibIndexingEnabled]'];
        $attestField  = $form['event[bibIndexingAttestation]'];
        $this->assertInstanceOf(ChoiceFormField::class, $enabledField);
        $this->assertInstanceOf(ChoiceFormField::class, $attestField);

        if ($enable) {
            $enabledField->tick();
        } else {
            $enabledField->untick();
        }

        if ($attest) {
            $attestField->tick();
        } else {
            $attestField->untick();
        }

        $this->client->submit($form);
    }

    public function testEnablingWithoutAttestationIsRejected(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $this->submitEdit($event, enable: true, attest: false);

        // Form redisplays (422 with validation error), flag stays off.
        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $this->em->clear();
        $reloaded = $this->em->getRepository(Event::class)->find($event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertFalse($reloaded->isBibIndexingEnabled());
    }

    public function testEnablingWithAttestationPersists(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $this->submitEdit($event, enable: true, attest: true);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Event::class)->find($event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertTrue($reloaded->isBibIndexingEnabled());
    }

    public function testAlreadyEnabledEventCanBeResavedWithoutReattesting(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        // Enable with attestation first
        $this->submitEdit($event, enable: true, attest: true);
        $this->em->clear();

        // Re-submit the edit form with flag still enabled but attestation unticked
        // and a description change to prove other changes work
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/edit');
        $form    = $crawler->selectButton('Save')->form();

        $enabledField = $form['event[bibIndexingEnabled]'];
        $attestField  = $form['event[bibIndexingAttestation]'];
        $this->assertInstanceOf(ChoiceFormField::class, $enabledField);
        $this->assertInstanceOf(ChoiceFormField::class, $attestField);

        // Keep enabled but uncheck attestation
        $enabledField->tick();
        $attestField->untick();

        // Make an unrelated change (description)
        $descriptionField = $form['event[description]'];
        $this->assertInstanceOf(FormField::class, $descriptionField);
        $descriptionField->setValue('Updated description');

        $this->client->submit($form);

        // Should succeed (redirect to event view or form redisplay without error)
        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        // Flag should remain enabled
        $this->em->clear();
        $reloaded = $this->em->getRepository(Event::class)->find($event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertTrue($reloaded->isBibIndexingEnabled());
    }

    public function testDisablingDoesNotRequireAttestation(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        // Enable with attestation first
        $this->submitEdit($event, enable: true, attest: true);
        $this->em->clear();

        // Now disable without attestation
        $this->submitEdit($event, enable: false, attest: false);

        // Should succeed (redirect)
        $response = $this->client->getResponse();
        $this->assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        // Flag should be disabled
        $this->em->clear();
        $reloaded = $this->em->getRepository(Event::class)->find($event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertFalse($reloaded->isBibIndexingEnabled());
    }
}
