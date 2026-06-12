<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\EventCollection;
use App\Entity\User;
use App\Repository\EventCollectionRepository;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventCollectionRepository $collections,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $eventCriteria = $isAdmin ? [] : ['owner' => $user];
        $collectionCriteria = $isAdmin ? [] : ['owner' => $user];
        /** @var list<Event> $eventList */
        $eventList = $this->events->findBy($eventCriteria, ['startsAt' => 'DESC'], 25);
        /** @var list<EventCollection> $collectionList */
        $collectionList = $this->collections->findBy($collectionCriteria, ['name' => 'ASC'], 25);
        return $this->render('admin/dashboard.html.twig', [
            'events'      => $eventList,
            'collections' => $collectionList,
        ]);
    }
}
