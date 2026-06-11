<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Throwable;
use DateTimeImmutable;
use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Message\ProcessPhoto;
use App\Repository\PhotoRepository;
use App\Security\Voter\EventVoter;
use App\Security\Voter\PhotoVoter;
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

final class PhotoController extends AbstractController
{
    private const int MAX_BYTES = 25 * 1024 * 1024;

    private const int PER_PAGE = 100;

    private const string STALE_PENDING_THRESHOLD = '-5 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhotoRepository $photos,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'photo_originals_storage')]
        private readonly FilesystemOperator $originals,
        #[Autowire(service: 'photo_thumbs_storage')]
        private readonly FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private readonly FilesystemOperator $previews,
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
        '/admin/events/{eventId}/photos/{photoId}/retry',
        name: 'admin_photo_retry',
        requirements: ['eventId' => '\d+', 'photoId' => '\d+'],
        methods: ['POST'],
    )]
    public function retry(int $eventId, int $photoId, Request $request): RedirectResponse
    {
        $photo = $this->loadOrThrow($eventId, $photoId);
        $this->denyAccessUnlessGranted(PhotoVoter::EDIT, $photo);
        $this->assertCsrf($request, 'retry_photo_' . $photoId);

        if ($photo->getStatus() === PhotoStatus::Failed) {
            $photo->resetForRetry();
            $this->em->flush();
        }

        // For pending/ready: no state change. Either way, re-dispatching is safe (handler is idempotent).
        $this->bus->dispatch(new ProcessPhoto($photoId));

        $this->addFlash('success', 'Photo re-queued.');

        return $this->redirectToRoute('admin_photo_grid', [
            'id'   => $eventId,
            'page' => max(1, $request->request->getInt('page', 1)),
        ]);
    }

    #[Route(
        '/admin/events/{id}/photos/delete-all',
        name: 'admin_photo_delete_all',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function deleteAll(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'delete_all_photos_' . $event->getId());

        $eventId = (int) $event->getId();
        $this->photos->deleteAllForEvent($event);

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
