<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\User;
use App\Form\SetupFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SetupController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Security $security,
    ) {
    }

    #[Route('/setup', name: 'app_setup', methods: ['GET', 'POST'])]
    public function start(Request $request): Response
    {
        if ($this->users->count([]) > 0) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(SetupFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, displayName: string} $data */
            $data = $form->getData();
            /** @var string $plain */
            $plain = $form->get('plainPassword')->getData();

            $admin = new User($data['email'], $data['displayName']);
            $admin->addRole('ROLE_ADMIN');
            $admin->setPassword($this->passwordHasher->hashPassword($admin, $plain));
            $this->em->persist($admin);
            $this->em->flush();

            $this->security->login($admin, 'form_login', 'main');

            return new RedirectResponse('/admin');
        }

        return $this->render('setup/start.html.twig', ['form' => $form]);
    }
}
