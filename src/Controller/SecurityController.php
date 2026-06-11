<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\LoginFormType;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    public function __construct(private readonly AuthenticationUtils $authenticationUtils)
    {
    }

    #[Route('/login', name: 'app_login')]
    public function login(): Response
    {
        $form = $this->createForm(LoginFormType::class, null, [
            'last_username' => $this->authenticationUtils->getLastUsername(),
            'action'        => $this->generateUrl('app_login'),
        ]);

        return $this->render('security/login.html.twig', [
            'form'  => $form,
            'error' => $this->authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new LogicException('Intercepted by firewall.');
    }
}
