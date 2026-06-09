<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Entity\EventCollection;
use App\Entity\User;
use App\Form\EventCollectionType;
use App\Repository\EventCollectionRepository;
use App\Security\Voter\EventCollectionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EventCollectionController extends AbstractController
{
    public function __construct(
        private readonly EventCollectionRepository $collections,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/collections', name: 'admin_collection_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $criteria = $this->isGranted('ROLE_ADMIN') ? [] : ['owner' => $user];
        return $this->render('admin/collection/index.html.twig', [
            'collections' => $this->collections->findBy($criteria, ['name' => 'ASC']),
        ]);
    }

    #[Route('/admin/collections/new', name: 'admin_collection_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $collection = new EventCollection('', '', $user);

        $form = $this->createForm(EventCollectionType::class, $collection);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($collection);
            $this->em->flush();
            $this->addFlash('success', 'Collection created.');

            return $this->redirectToRoute('admin_collection_index');
        }

        return $this->render('admin/collection/form.html.twig', [
            'form'       => $form,
            'collection' => $collection,
            'mode'       => 'new',
        ]);
    }

    #[Route(
        '/admin/collections/{id}/edit',
        name: 'admin_collection_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(EventCollection $collection, Request $request): Response
    {
        $this->denyAccessUnlessGranted(EventCollectionVoter::EDIT, $collection);

        $form = $this->createForm(EventCollectionType::class, $collection);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Collection updated.');

            return $this->redirectToRoute('admin_collection_index');
        }

        return $this->render('admin/collection/form.html.twig', [
            'form'       => $form,
            'collection' => $collection,
            'mode'       => 'edit',
        ]);
    }

    #[Route(
        '/admin/collections/{id}/delete',
        name: 'admin_collection_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function delete(EventCollection $collection, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventCollectionVoter::DELETE, $collection);

        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->isCsrfTokenValid('delete_collection_' . $collection->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->em->remove($collection);
        $this->em->flush();

        $this->addFlash('success', 'Collection deleted.');

        return $this->redirectToRoute('admin_collection_index');
    }
}
