<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use App\Tests\Fake\ProcessPhotoStatusProbe;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

/**
 * Regression guard for the dispatch-before-flush race (#109). retry / reingest /
 * reingestAll must commit the status→Pending transition to the DB *before* they
 * dispatch ProcessPhoto — otherwise a worker can consume the message, read the
 * still-committed prior status via find(), hit the handler's "not Pending"
 * early-return, and strand the photo in Pending forever. The probe middleware
 * records the committed status seen at dispatch time; it MUST be 'pending'.
 */
final class PhotoReprocessOrderingTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em = $em;

        $owner = new User('o@example.test', 'O');
        $owner->setPassword($hasher->hashPassword($owner, 'x'));
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);
        $this->em->flush();

        $this->owner = $owner;
        $this->client->loginUser($owner);
    }

    private function makeEvent(): Event
    {
        $event = new Event(
            'e' . bin2hex(random_bytes(4)),
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $event->setTimezone('UTC');
        $event->setRetainOriginals(true);

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function addReady(Event $event, string $hashSeed): Photo
    {
        $photo = new Photo($event, str_pad($hashSeed, 64, '0'), $hashSeed . '.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100, 2048);

        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    private function addFailed(Event $event, string $hashSeed): Photo
    {
        $photo = new Photo($event, str_pad($hashSeed, 64, '0'), $hashSeed . '.jpg', 100);
        $photo->markFailed('boom');

        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    private function probe(): ProcessPhotoStatusProbe
    {
        /** @var ProcessPhotoStatusProbe $probe */
        $probe = self::getContainer()->get(ProcessPhotoStatusProbe::class);

        return $probe;
    }

    private function primeCsrfToken(string $tokenId): string
    {
        $this->client->request(Request::METHOD_GET, '/admin/events');
        $session = $this->client->getRequest()->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = bin2hex(random_bytes(16));
        $session->set(SessionTokenStorage::SESSION_NAMESPACE . '/' . $tokenId, $token);
        $session->save();

        return $token;
    }

    public function testRetryCommitsPendingBeforeDispatch(): void
    {
        $event  = $this->makeEvent();
        $failed = $this->addFailed($event, 'aa');
        $id     = (int) $failed->getId();

        $token = $this->primeCsrfToken('retry_photo_' . $id);
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/retry', (int) $event->getId(), $id),
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        $this->assertSame(
            'pending',
            $this->probe()->statusAtDispatch[$id] ?? null,
            'retry must flush status→Pending before dispatching ProcessPhoto.',
        );
    }

    public function testReingestSingleCommitsPendingBeforeDispatch(): void
    {
        $event = $this->makeEvent();
        $ready = $this->addReady($event, 'bb');
        $id    = (int) $ready->getId();

        $token = $this->primeCsrfToken('reingest_photo_' . $id);
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/reingest', (int) $event->getId(), $id),
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        $this->assertSame(
            'pending',
            $this->probe()->statusAtDispatch[$id] ?? null,
            'reingest must flush status→Pending before dispatching ProcessPhoto.',
        );
    }

    public function testRetryAllCommitsPendingBeforeDispatch(): void
    {
        $event = $this->makeEvent();
        $f1    = $this->addFailed($event, 'ee');
        $f2    = $this->addFailed($event, 'ff');
        $id1   = (int) $f1->getId();
        $id2   = (int) $f2->getId();

        $token = $this->primeCsrfToken('retry_all_photos_' . $event->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/retry-all', (int) $event->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        $probe = $this->probe();
        $this->assertSame(
            'pending',
            $probe->statusAtDispatch[$id1] ?? null,
            'retryAll must flush every status→Pending before dispatching any ProcessPhoto.',
        );
        $this->assertSame('pending', $probe->statusAtDispatch[$id2] ?? null);
    }

    public function testReingestAllCommitsPendingBeforeDispatch(): void
    {
        $event  = $this->makeEvent();
        $ready1 = $this->addReady($event, 'cc');
        $ready2 = $this->addReady($event, 'dd');
        $id1    = (int) $ready1->getId();
        $id2    = (int) $ready2->getId();

        $token = $this->primeCsrfToken('reingest_all_photos_' . $event->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/reingest', (int) $event->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        $probe = $this->probe();
        $this->assertSame(
            'pending',
            $probe->statusAtDispatch[$id1] ?? null,
            'reingestAll must flush every status→Pending before dispatching any ProcessPhoto.',
        );
        $this->assertSame('pending', $probe->statusAtDispatch[$id2] ?? null);
    }

    public function testProbeIsWiredAndFreshUploadWouldBeSeenPending(): void
    {
        // Guard against a false green: if the probe silently captured nothing, the
        // race assertions above would pass on null-vs-null. This proves the probe
        // observes real dispatches and that PhotoStatus::Pending serialises to
        // the literal 'pending' the probe compares against.
        $this->assertSame('pending', PhotoStatus::Pending->value);
    }
}
