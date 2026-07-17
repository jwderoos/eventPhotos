<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\Attribute\AuditIgnore;
use App\Audit\Attribute\Audited;
use App\Entity\BibSuppression;
use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Message\ProcessPhoto;
use App\Repository\BibSuppressionRepository;
use App\Repository\PhotoAttributeRepository;
use App\Repository\PhotoRepository;
use App\Security\Voter\EventVoter;
use App\Security\Voter\PhotoVoter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class PhotoController extends AbstractController
{
    // Mirrored client-side in assets/controllers/photo_uploader_controller.js (MAX_BYTES + UI copy).
    // Keep both in sync — tests/Unit/Asset/PhotoUploaderCapTest.php guards against drift.
    private const int MAX_BYTES = 10 * 1024 * 1024;

    private const int PER_PAGE = 100;

    private const string STALE_PENDING_THRESHOLD = '-5 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhotoRepository $photos,
        private readonly BibSuppressionRepository $bibSuppressions,
        private readonly PhotoAttributeRepository $photoAttributes,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'photo_originals_storage')]
        private readonly FilesystemOperator $originals,
        #[Autowire(service: 'photo_thumbs_storage')]
        private readonly FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private readonly FilesystemOperator $previews,
        private readonly AuditContext $audit,
    ) {
    }

    #[Route(
        '/admin/events/{id}/photos',
        name: 'admin_event_photo_manage',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function manage(Event $event): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        return $this->render('admin/event/photos_manage.html.twig', [
            'event' => $event,
        ]);
    }

    #[Route(
        '/admin/events/{id}/photos',
        name: 'admin_photo_upload',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[AuditIgnore]
    public function upload(Event $event, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['error' => 'Missing file.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$file->isValid()) {
            $status = $file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE
                ? Response::HTTP_REQUEST_ENTITY_TOO_LARGE
                : Response::HTTP_BAD_REQUEST;
            return new JsonResponse(['error' => $file->getErrorMessage()], $status);
        }

        if ($file->getMimeType() !== 'image/jpeg') {
            return new JsonResponse(['error' => 'Only JPEG accepted.'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return new JsonResponse(['error' => 'File too large.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $hash     = (string) hash_file('sha256', (string) $file->getRealPath());
        $existing = $this->photos->findOneBy(['event' => $event, 'contentHash' => $hash]);
        if ($existing !== null) {
            return new JsonResponse(
                ['status' => 'duplicate', 'photoId' => $existing->getId()],
                Response::HTTP_OK,
            );
        }

        $photo = new Photo(
            event: $event,
            contentHash: $hash,
            originalFilename: $file->getClientOriginalName(),
            byteSize: (int) $file->getSize(),
        );
        $this->em->persist($photo);
        $this->em->flush(); // need the id before naming the storage path

        $path   = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $stream = fopen((string) $file->getRealPath(), 'rb');
        if ($stream === false) {
            $this->em->remove($photo);
            $this->em->flush();

            return new JsonResponse(
                ['error' => 'Could not read uploaded file.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        try {
            $this->originals->writeStream($path, $stream);
        } catch (Throwable $throwable) {
            $this->em->remove($photo);
            $this->em->flush();
            throw $throwable;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->bus->dispatch(new ProcessPhoto((int) $photo->getId()));

        $rowHtml = $this->renderView('admin/event/_photo_row.html.twig', [
            'event' => $event,
            'photo' => $photo,
        ]);

        return new JsonResponse(
            [
                'status'  => 'pending',
                'photoId' => $photo->getId(),
                'rowHtml' => $rowHtml,
            ],
            Response::HTTP_ACCEPTED,
        );
    }

    #[Route(
        '/admin/events/{id}/photos-grid',
        name: 'admin_photo_grid',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function gridFrame(Event $event, Request $request): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $page = max(1, $request->query->getInt('page', 1));

        $result = $this->photos->paginateForEvent($event, $page, self::PER_PAGE);
        /** @var list<Photo> $photos */
        $photos = $result['photos'];
        /** @var int $total */
        $total = $result['total'];

        $hasStalePending = false;
        $cutoff          = new DateTimeImmutable(self::STALE_PENDING_THRESHOLD);
        foreach ($photos as $p) {
            if ($p->getStatus() === PhotoStatus::Pending && $p->getCreatedAt() < $cutoff) {
                $hasStalePending = true;
                break;
            }
        }

        return $this->render('admin/event/photos_grid.html.twig', [
            'event'           => $event,
            'photos'          => $photos,
            'total'           => $total,
            'page'            => $page,
            'perPage'         => self::PER_PAGE,
            'hasStalePending' => $hasStalePending,
        ]);
    }

    #[Route(
        '/admin/events/{id}/photos/delete-all',
        name: 'admin_photo_delete_all',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::PhotoDeleteAll, targetParam: 'id', targetType: 'Event')]
    public function deleteAll(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'delete_all_photos_' . $event->getId());

        $eventId = (int) $event->getId();
        $deletedCount = $this->photos->deleteAllForEvent($event);
        $this->audit->set('deleted_count', $deletedCount);

        $dir = sprintf('event-%d', $eventId);
        foreach ([$this->originals, $this->thumbs, $this->previews] as $fs) {
            try {
                $fs->deleteDirectory($dir);
            } catch (FilesystemException) {
                // Best-effort — pipeline may not have produced files yet.
            }
        }

        return $this->redirectToRoute('admin_photo_grid', ['id' => $eventId]);
    }

    #[Route(
        '/admin/events/{eventId}/photos/{photoId}/delete',
        name: 'admin_photo_delete',
        requirements: ['eventId' => '\d+', 'photoId' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::PhotoDelete, targetParam: 'photoId', targetType: 'Photo')]
    public function delete(int $eventId, int $photoId, Request $request): RedirectResponse
    {
        $photo = $this->loadOrThrow($eventId, $photoId);
        $this->denyAccessUnlessGranted(PhotoVoter::DELETE, $photo);
        $this->assertCsrf($request, 'delete_photo_' . $photoId);

        $path = sprintf('event-%d/%d.jpg', $eventId, $photoId);
        foreach ([$this->originals, $this->thumbs, $this->previews] as $fs) {
            try {
                $fs->delete($path);
            } catch (FilesystemException) {
                // Missing files are fine — pipeline may not have produced them yet.
            }
        }

        $this->em->remove($photo);
        $this->em->flush();

        return $this->redirectToRoute('admin_photo_grid', [
            'id'   => $eventId,
            'page' => max(1, $request->request->getInt('page', 1)),
        ]);
    }

    #[Route(
        '/admin/events/{id}/photos/reingest',
        name: 'admin_photo_reingest_all',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::PhotoReingestAll, targetParam: 'id', targetType: 'Event')]
    public function reingestAll(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'reingest_all_photos_' . $event->getId());

        $eventId = (int) $event->getId();

        if (!$event->isRetainOriginals()) {
            $this->addFlash('error', 'Re-ingest is unavailable: this event does not retain originals.');

            return $this->redirectToRoute('admin_photo_grid', ['id' => $eventId]);
        }

        /** @var list<Photo> $ready */
        $ready = $this->photos->findBy(['event' => $event, 'status' => PhotoStatus::Ready]);
        foreach ($ready as $photo) {
            $photo->resetForReingest();
            $this->bus->dispatch(new ProcessPhoto((int) $photo->getId(), reingest: true));
        }

        $this->em->flush();
        $this->audit->set('reingested_count', count($ready));
        $this->addFlash('success', sprintf('Re-ingesting %d photos.', count($ready)));

        return $this->redirectToRoute('admin_photo_grid', ['id' => $eventId]);
    }

    #[Route(
        '/admin/events/{eventId}/photos/{photoId}/reingest',
        name: 'admin_photo_reingest',
        requirements: ['eventId' => '\d+', 'photoId' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::PhotoReingest, targetParam: 'photoId', targetType: 'Photo')]
    public function reingest(int $eventId, int $photoId, Request $request): RedirectResponse
    {
        $photo = $this->loadOrThrow($eventId, $photoId);
        $this->denyAccessUnlessGranted(PhotoVoter::EDIT, $photo);
        $this->assertCsrf($request, 'reingest_photo_' . $photoId);

        $page   = max(1, $request->request->getInt('page', 1));
        $target = ['id' => $eventId, 'page' => $page];

        if (!$photo->getEvent()->isRetainOriginals()) {
            $this->addFlash('error', 'Re-ingest is unavailable: this event does not retain originals.');

            return $this->redirectToRoute('admin_photo_grid', $target);
        }

        if ($photo->getStatus() !== PhotoStatus::Ready) {
            $this->addFlash('error', 'Only ready photos can be re-ingested.');

            return $this->redirectToRoute('admin_photo_grid', $target);
        }

        $photo->resetForReingest();
        $this->bus->dispatch(new ProcessPhoto($photoId, reingest: true));
        $this->em->flush();

        return $this->redirectToRoute('admin_photo_grid', $target);
    }

    #[Route(
        '/admin/events/{id}/bib-suppressions',
        name: 'admin_bib_suppress',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::EventBibSuppress, targetParam: 'id', targetType: 'Event')]
    public function suppressBib(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'suppress_bib_' . $event->getId());

        $bibNumber = trim((string) $request->request->get('bibNumber'));
        if ($bibNumber === '') {
            $this->addFlash('error', 'Enter a bib number to suppress.');

            return $this->redirectToRoute('admin_photo_grid', ['id' => $event->getId()]);
        }

        if (mb_strlen($bibNumber) > BibSuppression::MAX_BIB_NUMBER_LENGTH) {
            $this->addFlash('error', 'Bib number is too long.');

            return $this->redirectToRoute('admin_photo_grid', ['id' => $event->getId()]);
        }

        // Plan C: delete every already-stored bib PhotoAttribute row event-wide so the
        // bib disappears from search immediately (not just on the next re-ingest). The
        // BibSuppression insert below (Plan A) blocks any future re-add on re-ingest.
        $this->photoAttributes->deleteBibForEvent($event, $bibNumber);

        if (!$this->bibSuppressions->isSuppressed($event, $bibNumber)) {
            $this->em->persist(new BibSuppression($event, $bibNumber));
            $this->em->flush();
        }

        $this->audit->set('suppressed_bib', $bibNumber);
        $this->addFlash('success', sprintf('Bib %s will not be indexed.', $bibNumber));

        return $this->redirectToRoute('admin_photo_grid', ['id' => $event->getId()]);
    }

    #[Route(
        '/admin/events/{eventId}/photos/{photoId}/retry',
        name: 'admin_photo_retry',
        requirements: ['eventId' => '\d+', 'photoId' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::PhotoRetry, targetParam: 'photoId', targetType: 'Photo')]
    public function retry(int $eventId, int $photoId, Request $request): RedirectResponse
    {
        $photo = $this->loadOrThrow($eventId, $photoId);
        $this->denyAccessUnlessGranted(PhotoVoter::EDIT, $photo);
        $this->assertCsrf($request, 'retry_photo_' . $photoId);

        $page   = max(1, $request->request->getInt('page', 1));
        $target = ['id' => $eventId, 'page' => $page];

        // Retry re-runs ingest from the stored original. A failed photo's original is
        // deleted at failure time unless the event retains originals (see ProcessPhotoHandler),
        // so retry is only possible when originals are kept.
        if (!$photo->getEvent()->isRetainOriginals()) {
            $this->addFlash('error', 'Retry is unavailable: this event does not retain originals.');

            return $this->redirectToRoute('admin_photo_grid', $target);
        }

        if ($photo->getStatus() !== PhotoStatus::Failed) {
            $this->addFlash('error', 'Only failed photos can be retried.');

            return $this->redirectToRoute('admin_photo_grid', $target);
        }

        $photo->resetForRetry();
        // Fresh ingest attempt (reingest: false) so the ingest window guard applies — the
        // organizer typically widens the event window before retrying a window rejection.
        $this->bus->dispatch(new ProcessPhoto($photoId));
        $this->em->flush();

        return $this->redirectToRoute('admin_photo_grid', $target);
    }

    private function loadOrThrow(int $eventId, int $photoId): Photo
    {
        $photo = $this->photos->find($photoId);
        if ($photo === null || $photo->getEvent()->getId() !== $eventId) {
            throw $this->createNotFoundException();
        }

        return $photo;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
