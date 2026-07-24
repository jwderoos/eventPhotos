<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\BibSuppression;
use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoAttribute;
use App\Entity\PhotoAttributeType;
use App\Entity\User;
use App\Repository\BibSuppressionRepository;
use App\Repository\PhotoAttributeRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class BibSuppressionActionTest extends WebTestCase
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

    /** @return array{0: User, 1: Event} */
    private function makeOrganizerWithEvent(): array
    {
        $user = new User('owner2@example.test', 'Owner');
        $user->addRole('ROLE_ORGANIZER');

        $this->em->persist($user);

        $event = new Event(
            'bib-run-2',
            'Bib Run 2',
            new DateTimeImmutable('2026-05-01 09:00'),
            new DateTimeImmutable('2026-05-01 12:00'),
            $user,
        );
        $this->em->persist($event);
        $this->em->flush();

        return [$user, $event];
    }

    public function testSuppressBibRequiresCsrf(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-suppressions', [
            'bibNumber' => '1423',
            '_token'    => 'wrong',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuppressBibInsertsRow(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $token = $this->primeCsrfToken('suppress_bib_' . $event->getId());

        $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-suppressions', [
            'bibNumber' => '1423',
            '_token'    => $token,
        ]);

        self::assertResponseRedirects();

        /** @var BibSuppressionRepository $repo */
        $repo = self::getContainer()->get(BibSuppressionRepository::class);
        $this->assertTrue($repo->isSuppressed($event, '1423'));
    }

    public function testSuppressingSameBibTwiceIsIdempotent(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        foreach (['1423', '1423'] as $bib) {
            $token = $this->primeCsrfToken('suppress_bib_' . $event->getId());
            $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-suppressions', [
                'bibNumber' => $bib,
                '_token'    => $token,
            ]);
        }

        $this->assertResponseRedirects();

        /** @var BibSuppressionRepository $repo */
        $repo = self::getContainer()->get(BibSuppressionRepository::class);
        $this->assertTrue($repo->isSuppressed($event, '1423'));

        $count = $this->em->createQueryBuilder()
            ->select('COUNT(bs)')
            ->from(BibSuppression::class, 'bs')
            ->where('bs.event = :event AND bs.bibNumber = :bibNumber')
            ->setParameter('event', $event)
            ->setParameter('bibNumber', '1423')
            ->getQuery()
            ->getSingleScalarResult();
        $this->assertSame(1, $count);
    }

    public function testSuppressBibKeepsStoredBibTags(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();

        $photo = new Photo($event, str_pad('c1', 64, '0'), 'p.jpg', 1000);
        $this->em->persist($photo);
        $this->em->flush();

        $this->em->persist(new PhotoAttribute($photo, PhotoAttributeType::Bib, '1423', 0.99));
        $this->em->persist(new PhotoAttribute($photo, PhotoAttributeType::ClothingColor, 'orange', 0.9));
        $this->em->flush();

        $this->client->loginUser($user);
        $token = $this->primeCsrfToken('suppress_bib_' . $event->getId());

        $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-suppressions', [
            'bibNumber' => '1423',
            '_token'    => $token,
        ]);

        self::assertResponseRedirects();

        /** @var PhotoAttributeRepository $repo */
        $repo = self::getContainer()->get(PhotoAttributeRepository::class);
        // Reversible overlay: the bib row is NOT deleted, only flagged suppressed.
        $this->assertCount(1, $repo->findBy(['type' => PhotoAttributeType::Bib, 'value' => '1423']));
        $this->assertCount(1, $repo->findBy(['type' => PhotoAttributeType::ClothingColor, 'value' => 'orange']));

        /** @var BibSuppressionRepository $suppressions */
        $suppressions = self::getContainer()->get(BibSuppressionRepository::class);
        $this->assertTrue($suppressions->isSuppressed($event, '1423'));
    }

    public function testReindexBibRemovesSuppression(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->em->persist(new BibSuppression($event, '1423'));
        $this->em->flush();

        $this->client->loginUser($user);
        $token = $this->primeCsrfToken('reindex_bib_' . $event->getId());

        $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-reindex', [
            'bibNumber' => '1423',
            '_token'    => $token,
        ]);

        self::assertResponseRedirects();

        /** @var BibSuppressionRepository $repo */
        $repo = self::getContainer()->get(BibSuppressionRepository::class);
        $this->assertFalse($repo->isSuppressed($event, '1423'));
    }

    public function testReindexBibRequiresCsrf(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-reindex', [
            'bibNumber' => '1423',
            '_token'    => 'wrong',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTagsPageShowsDeindexedBibWithUndoAndHidesItFromIndexed(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();

        $photo = new Photo($event, str_pad('t1', 64, '0'), 'p.jpg', 1000);
        $this->em->persist($photo);
        $this->em->flush();
        $this->em->persist(new PhotoAttribute($photo, PhotoAttributeType::Bib, '1423', 0.99));
        $this->em->persist(new BibSuppression($event, '1423'));
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/tags');

        self::assertResponseIsSuccessful();
        // A de-indexed bib appears in its own list with a re-index form, not as an indexed chip.
        $this->assertGreaterThan(
            0,
            $crawler->filter('form[action$="/bib-reindex"]')->count(),
            'expected an undo (re-index) form for the de-indexed bib',
        );
        self::assertSelectorTextContains('[data-role="deindexed-bib"]', '1423');
        // The suppressed bib must NOT appear as an indexed (clickable) chip.
        self::assertSelectorNotExists('a[data-role="tag-chip"][href*="bib=1423"]');
    }

    public function testPhotoGridPageHasNoDeindexForm(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $this->client->request(
            Request::METHOD_GET,
            '/admin/events/' . $event->getId() . '/photos-grid',
        );

        self::assertResponseIsSuccessful();
        // The de-index form lives on the tags page (#117 review); the photo grid
        // must not post to the suppress route.
        self::assertSelectorNotExists('form[action$="/bib-suppressions"]');
    }

    public function testReindexBibWithoutMatchingSuppressionIsNoOp(): void
    {
        [$user, $event] = $this->makeOrganizerWithEvent();
        $this->client->loginUser($user);

        $token = $this->primeCsrfToken('reindex_bib_' . $event->getId());

        $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-reindex', [
            'bibNumber' => '9999',
            '_token'    => $token,
        ]);

        self::assertResponseRedirects();

        $count = $this->em->createQueryBuilder()
            ->select('COUNT(bs)')
            ->from(BibSuppression::class, 'bs')
            ->where('bs.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleScalarResult();
        $this->assertSame(0, $count);
    }
}
