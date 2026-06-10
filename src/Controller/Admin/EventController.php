<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\User;
use App\Form\EventType;
use App\Repository\EventRepository;
use App\Security\Voter\EventVoter;
use App\Service\QrCodeRenderer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EventController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EntityManagerInterface $em,
        private readonly QrCodeRenderer $renderer,
        private readonly UrlGeneratorInterface $urlGenerator,
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
        return $this->render('admin/event/index.html.twig', [
            'events' => $this->events->findBy($criteria, ['date' => 'DESC']),
        ]);
    }

    #[Route('/admin/events/new', name: 'admin_event_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $event = new Event('', '', new DateTimeImmutable('today'), $user);

        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($event);
            $this->em->flush();

            $this->addFlash('success', 'Event created.');

            return $this->redirectToRoute('admin_event_index');
        }

        return $this->render('admin/event/form.html.twig', [
            'form'  => $form,
            'event' => $event,
            'mode'  => 'new',
        ]);
    }

    #[Route(
        '/admin/events/{id}/edit',
        name: 'admin_event_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(Event $event, Request $request): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Event updated.');

            return $this->redirectToRoute('admin_event_index');
        }

        return $this->render('admin/event/form.html.twig', [
            'form'  => $form,
            'event' => $event,
            'mode'  => 'edit',
        ]);
    }

    #[Route('/admin/events/{id}/delete', name: 'admin_event_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::DELETE, $event);

        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->isCsrfTokenValid('delete_event_' . $event->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->em->remove($event);
        $this->em->flush();

        $this->addFlash('success', 'Event deleted.');

        return $this->redirectToRoute('admin_event_index');
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

        return $this->render('admin/event/qr.html.twig', [
            'event' => $event,
            'url'   => $url,
            'svg'   => $this->renderer->svg($url),
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

        return new Response(
            $this->renderer->png($url),
            Response::HTTP_OK,
            [
                'Content-Type'        => 'image/png',
                'Content-Disposition' => sprintf('attachment; filename="event-%s.png"', $event->getSlug()),
            ],
        );
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
