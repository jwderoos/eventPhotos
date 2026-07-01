<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\RequestPasswordResetFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    private const int TOKEN_LIFETIME_SECONDS = 3600;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/reset-password', name: 'app_forgot_password_request')]
    public function request(Request $request): Response
    {
        $form = $this->createForm(RequestPasswordResetFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string} $data */
            $data = $form->getData();
            $this->sendPasswordResetEmail($data['email']);

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form->createView(),
        ]);
    }

    #[Route('/reset-password/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig', [
            'tokenLifetime' => self::TOKEN_LIFETIME_SECONDS,
        ]);
    }

    #[Route('/reset-password/reset/{token}', name: 'app_reset_password')]
    public function reset(
        Request $request,
        ?string $token = null,
    ): Response {
        if ($token !== null) {
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if ($token === null) {
            throw $this->createNotFoundException('No reset password token found in URL or in session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $resetPasswordException) {
            $this->addFlash('reset_password_error', sprintf(
                'There was a problem validating your reset request — %s',
                $resetPasswordException->getReason(),
            ));

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $this->em->flush();

            $this->cleanSessionAfterReset();

            $this->addFlash('success', 'Your password has been updated. You can sign in now.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form->createView(),
        ]);
    }

    private function sendPasswordResetEmail(string $emailAddress): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $emailAddress]);
        if ($user === null) {
            return;
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (TooManyPasswordRequestsException | ResetPasswordExceptionInterface) {
            return;
        }

        $email = new TemplatedEmail()
            ->from(new Address('no-reply@eventPhotos.local', 'eventPhotos'))
            ->to($user->getEmail())
            ->subject('Reset your eventPhotos password')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context([
                'user'       => $user,
                'resetToken' => $resetToken,
            ]);

        $this->mailer->send($email);

        $this->setTokenObjectInSession($resetToken);
    }
}
