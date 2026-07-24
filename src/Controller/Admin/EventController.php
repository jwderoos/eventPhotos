<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\Attribute\Audited;
use App\Audit\Attribute\AuditIgnore;
use App\Entity\Event;
use App\Entity\User;
use App\Form\EventImportType;
use App\Form\EventType;
use App\Message\SendEventLiveNotifications;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\EventRepository;
use App\Repository\PhotoRepository;
use App\Security\Voter\EventVoter;
use App\Service\Brand\BrandPreviewResolver;
use App\Service\Event\BannerUploader;
use App\Service\Event\Archive\InvalidArchiveException;
use App\Service\Event\Archive\SlugAlreadyExistsException;
use App\Service\Event\EventArchiveExporter;
use App\Service\Event\EventArchiveImporter;
use App\Service\Mail\OrganizerMailerResolver;
use App\Service\Notification\PendingConfirmationResender;
use App\Service\QrCodeRenderer;
use App\Service\Style\StyleResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Form\FormInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EventController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly PhotoRepository $photos,
        private readonly EntityManagerInterface $em,
        private readonly QrCodeRenderer $renderer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'event_logos_storage')]
        private readonly FilesystemOperator $eventLogosStorage,
        #[Autowire(service: 'photo_originals_storage')]
        private readonly FilesystemOperator $photoOriginals,
        #[Autowire(service: 'photo_thumbs_storage')]
        private readonly FilesystemOperator $photoThumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private readonly FilesystemOperator $photoPreviews,
        private readonly LoggerInterface $logger,
        private readonly OrganizerMailerResolver $mailerResolver,
        private readonly MessageBusInterface $bus,
        private readonly EventNotificationSubscriptionRepository $subscriptions,
        #[Autowire('%env(int:EVENT_LIVE_NOTIFICATION_RATE_PER_MIN)%')]
        private readonly int $notificationRatePerMinute,
        private readonly AuditContext $audit,
        private readonly StyleResolver $styleResolver,
        private readonly BrandPreviewResolver $brandPreview,
        private readonly BannerUploader $bannerUploader,
        private readonly EventArchiveExporter $exporter,
        private readonly EventArchiveImporter $importer,
        private readonly PendingConfirmationResender $pendingResender,
    ) {
    }

    #[Route('/admin/events', name: 'admin_event_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $criteria = $this->isGranted('ROLE_ADMIN') ? [] : ['owner' => $user];
        $events   = $this->events->findBy($criteria, ['startsAt' => 'DESC']);

        $eventIds = array_values(array_filter(array_map(
            static fn (Event $e): ?int => $e->getId(),
            $events,
        )));

        return $this->render('admin/event/index.html.twig', [
            'events'        => $events,
            'storageByEvent' => $this->photos->sumBytesByEventIds($eventIds),
        ]);
    }

    #[Route('/admin/events/new', name: 'admin_event_new', methods: ['GET', 'POST'])]
    #[Audited(AuditAction::EventCreate, targetParam: null)]
    public function new(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $now      = new DateTimeImmutable('today 10:00');
        $startsAt = $now;
        $endsAt   = $now->modify('+2 hours');
        $event    = new Event('', '', $startsAt, $endsAt, $user);

        $inherited = $this->styleResolver->resolveChain($this->styleResolver->profileStyleFor($user));
        $form      = $this->createForm(EventType::class, $event, [
            'mail_active'           => $this->mailerResolver->isCustomActive($event->getOwner()),
            'inherited'             => $inherited,
            'lock_retain_originals' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($event);
            $this->em->flush();

            $this->applyBanner($form, $event);
            $this->em->flush();

            $this->audit->set('created_id', $event->getId());
            $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');

            $this->addFlash('success', 'Event created.');

            return $this->redirectToRoute('admin_event_index');
        }

        return $this->render('admin/event/form.html.twig', [
            'form'           => $form,
            'event'          => $event,
            'mode'           => 'new',
            'styleInherited' => $inherited,
            'brandPreview'   => $this->brandPreview->forOwner($user),
        ]);
    }

    #[Route('/admin/events/import', name: 'admin_event_import', methods: ['GET', 'POST'])]
    #[Audited(AuditAction::EventImport, targetParam: null)]
    public function import(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(EventImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $archive = $form->get('archive')->getData();
            $owner   = $form->has('owner') && $form->get('owner')->getData() instanceof User
                ? $form->get('owner')->getData()
                : $user;

            if ($archive instanceof UploadedFile) {
                try {
                    $event = $this->importer->import($archive->getPathname(), $owner);

                    $this->audit->set('created_id', $event->getId());
                    $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');
                    $this->addFlash('success', sprintf('Imported event "%s".', $event->getName()));

                    return $this->redirectToRoute('admin_event_edit', ['id' => $event->getId()]);
                } catch (SlugAlreadyExistsException $e) {
                    $this->addFlash('error', sprintf(
                        'An event with slug "%s" already exists — import refused.',
                        $e->slug,
                    ));

                    return $this->redirectToRoute('admin_event_import');
                } catch (InvalidArchiveException $e) {
                    $this->addFlash('error', 'Invalid archive: ' . $e->getMessage());

                    return $this->redirectToRoute('admin_event_import');
                }
            }
        }

        return $this->render('admin/event/import.html.twig', ['form' => $form]);
    }

    #[Route(
        '/admin/events/{id}/edit',
        name: 'admin_event_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    #[Audited(AuditAction::EventEdit, targetParam: 'id', targetType: 'Event')]
    public function edit(Event $event, Request $request): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $mailActive = $this->mailerResolver->isCustomActive($event->getOwner());
        $inherited  = $this->styleResolver->resolveChain(
            $event->getCollection()?->getStyle(),
            $this->styleResolver->profileStyleFor($event->getOwner()),
        );

        $form = $this->createForm(EventType::class, $event, [
            'mail_active'           => $mailActive,
            'inherited'             => $inherited,
            'lock_retain_originals' => $event->getId() !== null && $this->photos->countForEvent($event) > 0,
        ]);
        $bibBefore = $event->isBibIndexingEnabled();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyBanner($form, $event);
            $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');

            if ($event->isBibIndexingEnabled() !== $bibBefore) {
                $this->audit->changed('bib_indexing', $bibBefore, $event->isBibIndexingEnabled());
                if ($event->isBibIndexingEnabled()) {
                    $this->audit->set('bib_indexing_attested', true);
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Event updated.');

            return $this->redirectToRoute('admin_event_index');
        }

        $rate           = max(1, $this->notificationRatePerMinute);
        $confirmedCount = $this->subscriptions->countConfirmedByEvent($event);

        return $this->render('admin/event/form.html.twig', [
            'form'             => $form,
            'event'            => $event,
            'mode'             => 'edit',
            'subscriberCount'  => $this->subscriptions->countByEvent($event),
            'confirmedCount'   => $confirmedCount,
            'pendingCount'     => $this->subscriptions->countPendingByEvent($event),
            'mailActive'       => $mailActive,
            'readyPhotoCount'  => $this->photos->countReady($event),
            'projectedMinutes' => (int) ceil($confirmedCount / $rate),
            'styleInherited'   => $inherited,
            'brandPreview'     => $this->brandPreview->forOwner($event->getOwner()),
        ]);
    }

    #[Route('/admin/events/{id}/delete', name: 'admin_event_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[Audited(AuditAction::EventDelete, targetParam: 'id', targetType: 'Event')]
    public function delete(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::DELETE, $event);

        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->isCsrfTokenValid('delete_event_' . $event->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $eventId = (int) $event->getId();

        // Snapshot key fields BEFORE the row is gone (terminate runs after the delete is flushed).
        $this->audit->snapshot(['name' => $event->getName(), 'slug' => $event->getSlug()]);
        $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');

        $this->em->remove($event);
        $this->em->flush();

        $dir = sprintf('event-%d', $eventId);
        foreach ([$this->photoOriginals, $this->photoThumbs, $this->photoPreviews] as $fs) {
            try {
                $fs->deleteDirectory($dir);
            } catch (FilesystemException) {
                // Best-effort — event may have had no photos / no derivatives.
            }
        }

        $this->addFlash('success', 'Event deleted.');

        return $this->redirectToRoute('admin_event_index');
    }

    #[Route(
        '/admin/events/{id}/publish',
        name: 'admin_event_publish',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::EventPublish, targetParam: 'id', targetType: 'Event')]
    public function publish(Event $event, Request $request): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('publish' . $event->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (
            $event->isPublished()
            || $this->photos->countReady($event) < 1
            || !$this->mailerResolver->isCustomActive($event->getOwner())
        ) {
            return new Response('Cannot publish this event.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $event->markPublished(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->em->flush();

        $this->bus->dispatch(new SendEventLiveNotifications((int) $event->getId()));

        $this->addFlash('success', 'Event published. Notifying confirmed subscribers.');

        return $this->redirectToRoute('admin_event_edit', ['id' => $event->getId()]);
    }

    #[Route(
        '/admin/events/{id}/notify/resend-pending',
        name: 'admin_event_notify_resend_pending',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[AuditIgnore]
    public function resendPendingConfirmations(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('resend_pending_' . $event->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($event->isPublished() || !$this->mailerResolver->isCustomActive($event->getOwner())) {
            $this->addFlash('error', 'Cannot re-send confirmations for this event.');

            return $this->redirectToRoute('admin_event_edit', ['id' => $event->getId()]);
        }

        $count = $this->pendingResender->resendAll($event);

        $this->addFlash('success', sprintf('Re-sent confirmation to %d unverified subscriber(s).', $count));

        return $this->redirectToRoute('admin_event_edit', ['id' => $event->getId()]);
    }

    #[Route(
        '/admin/events/{id}/qr',
        name: 'admin_event_qr',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function qr(
        Event $event,
    ): Response {
        $this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

        $url = $this->eventLandingUrl($event);

        $logoBytes = $this->readLogoBytes($event);
        // TODO: when user-level default logos exist, fall back to
        // $event->getOwner()->getDefaultLogo() bytes here when $event has no logo of its own.

        return $this->render('admin/event/qr.html.twig', [
            'event' => $event,
            'url'   => $url,
            'svg'   => $this->renderer->svg($url, $logoBytes),
        ]);
    }

    #[Route(
        '/admin/events/{id}/qr.png',
        name: 'admin_event_qr_png',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function qrPng(
        Event $event,
    ): Response {
        $this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

        $url = $this->eventLandingUrl($event);

        $logoBytes = $this->readLogoBytes($event);
        // TODO: when user-level default logos exist, fall back to
        // $event->getOwner()->getDefaultLogo() bytes here when $event has no logo of its own.

        return new Response(
            $this->renderer->png($url, $logoBytes),
            Response::HTTP_OK,
            [
                'Content-Type'        => 'image/png',
                'Content-Disposition' => sprintf('attachment; filename="event-%s.png"', $event->getSlug()),
            ],
        );
    }

    #[Route(
        '/admin/events/{id}/logo',
        name: 'admin_event_logo',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function logo(Event $event): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

        $filename = $event->getLogoFilename();
        if ($filename === null) {
            throw $this->createNotFoundException();
        }

        try {
            $contents = $this->eventLogosStorage->read($filename);
        } catch (FilesystemException) {
            throw $this->createNotFoundException();
        }

        $response = new Response($contents);
        $response->headers->set('Content-Type', $this->mimeFromExtension($filename));
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }

    /** @param FormInterface<Event> $form */
    private function applyBanner(FormInterface $form, Event $event): void
    {
        if ($form->get('removeBanner')->getData() === true) {
            $this->bannerUploader->remove($event);

            return;
        }

        $file = $form->get('bannerFile')->getData();
        if ($file instanceof UploadedFile) {
            $this->bannerUploader->upload($event, (string) file_get_contents($file->getPathname()));
        }
    }

    #[Route(
        '/admin/events/{id}/export',
        name: 'admin_event_export',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    #[Audited(AuditAction::EventExport, targetParam: 'id', targetType: 'Event')]
    public function export(Event $event): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

        $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');

        $response = new BinaryFileResponse($this->exporter->export($event));
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('event-%s.zip', $event->getSlug()),
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function readLogoBytes(Event $event): ?string
    {
        $filename = $event->getLogoFilename();
        if ($filename === null) {
            return null;
        }

        try {
            return $this->eventLogosStorage->read($filename);
        } catch (FilesystemException $filesystemException) {
            $this->logger->warning('Failed to read event logo; rendering QR without it', [
                'event_id' => $event->getId(),
                'filename' => $filename,
                'exception' => $filesystemException,
            ]);
            return null;
        }
    }

    private function mimeFromExtension(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png'           => 'image/png',
            'jpg', 'jpeg'   => 'image/jpeg',
            default         => 'application/octet-stream',
        };
    }

    private function eventLandingUrl(Event $event): string
    {
        return $this->urlGenerator->generate(
            'public_event_landing',
            ['slug' => $event->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
