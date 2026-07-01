<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\Attribute\Audited;
use App\Entity\User;
use App\Form\UserCreateType;
use App\Form\UserEditType;
use App\Repository\EventCollectionRepository;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use App\Security\Voter\UserVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly MailerInterface $mailer,
        private readonly EventRepository $events,
        private readonly EventCollectionRepository $collections,
        private readonly AuditContext $audit,
    ) {
    }

    #[Route('/admin/users', name: 'admin_user_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/user/index.html.twig', [
            'users' => $this->users->findBy([], ['email' => 'ASC']),
        ]);
    }

    #[Route('/admin/users/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    #[Audited(AuditAction::UserCreate, targetParam: null)]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(UserCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, displayName: string, role: string} $data */
            $data = $form->getData();

            if ($this->users->findOneByEmail($data['email']) instanceof User) {
                $form->get('email')->addError(new FormError('A user with that email already exists.'));
            } else {
                $user = new User($data['email'], $data['displayName']);
                $user->addRole($data['role']);
                $user->setPassword(
                    $this->passwordHasher->hashPassword($user, bin2hex(random_bytes(16))),
                );
                $this->em->persist($user);
                $this->em->flush();

                $this->audit->set('created_id', $user->getId());
                $this->audit->targetLabel($user->getEmail());

                $this->sendInviteEmail($user);

                $this->addFlash(
                    'success',
                    sprintf('User created. Reset email sent to %s.', $user->getEmail()),
                );
                return new RedirectResponse('/admin/users');
            }
        }

        $status = $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'mode' => 'new',
        ], new Response(null, $status));
    }

    #[Route(
        '/admin/users/{id}/edit',
        name: 'admin_user_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    #[Audited(AuditAction::UserEdit, targetParam: 'id', targetType: 'User')]
    public function edit(User $target, Request $request): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $target);

        $canEditRole = $this->isGranted(UserVoter::EDIT_ROLE, $target);

        $currentTopRole = $this->topRole($target);
        $form = $this->createForm(UserEditType::class, [
            'displayName' => $target->getDisplayName(),
            'role'        => $currentTopRole,
        ], ['can_edit_role' => $canEditRole]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string, role?: string} $data */
            $data = $form->getData();

            $target->setDisplayName($data['displayName']);

            if ($canEditRole && isset($data['role']) && $data['role'] !== $currentTopRole) {
                foreach (['ROLE_ADMIN', 'ROLE_ORGANIZER', 'ROLE_USER'] as $roleToClear) {
                    $target->removeRole($roleToClear);
                }

                $target->addRole($data['role']);
                $this->audit->changed('role', $currentTopRole, $data['role']);
                $this->audit->overrideAction(AuditAction::UserRoleChange);
            }

            $this->audit->targetLabel($target->getEmail());
            $this->em->flush();
            $this->addFlash('success', 'User updated.');
            return new RedirectResponse('/admin/users');
        }

        $status = $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        return $this->render('admin/user/form.html.twig', [
            'form'         => $form,
            'mode'         => 'edit',
            'target_id'    => $target->getId(),
            'target_email' => $target->getEmail(),
        ], new Response(null, $status));
    }

    #[Route(
        '/admin/users/{id}/delete',
        name: 'admin_user_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::UserDelete, targetParam: 'id', targetType: 'User')]
    public function delete(User $target, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(UserVoter::DELETE, $target);

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('delete_user_' . $target->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ownedEvents      = $this->events->countByOwner($target);
        $ownedCollections = $this->collections->countByOwner($target);
        if ($ownedEvents + $ownedCollections > 0) {
            $this->addFlash('error', sprintf(
                'Cannot delete — %s owns %d event(s) and %d collection(s). Reassign or delete them first.',
                $target->getEmail(),
                $ownedEvents,
                $ownedCollections,
            ));
            $this->audit->suppress();
            return new RedirectResponse('/admin/users/' . $target->getId() . '/edit');
        }

        $this->audit->snapshot(['email' => $target->getEmail()]);
        $this->audit->targetLabel($target->getEmail());

        $this->em->remove($target);
        $this->em->flush();
        $this->addFlash('success', 'User deleted.');

        return new RedirectResponse('/admin/users');
    }

    #[Route(
        '/admin/users/{id}/send-reset',
        name: 'admin_user_send_reset',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::UserSendReset, targetParam: 'id', targetType: 'User')]
    public function sendReset(User $target, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $target);

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('send_reset_' . $target->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->audit->targetLabel($target->getEmail());
        $this->sendInviteEmail($target);
        $this->addFlash('success', sprintf('Reset email sent to %s.', $target->getEmail()));

        return new RedirectResponse('/admin/users/' . $target->getId() . '/edit');
    }

    private function topRole(User $user): string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return 'ROLE_ADMIN';
        }

        if (in_array('ROLE_ORGANIZER', $roles, true)) {
            return 'ROLE_ORGANIZER';
        }

        return 'ROLE_USER';
    }

    private function sendInviteEmail(User $user): void
    {
        try {
            $token = $this->resetPasswordHelper->generateResetToken($user);
        } catch (TooManyPasswordRequestsException | ResetPasswordExceptionInterface) {
            $this->addFlash(
                'warning',
                'User created but a reset email could not be issued. Use "Resend reset" on the edit page.',
            );
            return;
        }

        $email = new TemplatedEmail()
            ->from(new Address('no-reply@eventPhotos.local', 'eventPhotos'))
            ->to($user->getEmail())
            ->subject('Set your eventPhotos password')
            ->htmlTemplate('reset_password/email.html.twig')
            ->context(['user' => $user, 'resetToken' => $token]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface) {
            $this->addFlash(
                'warning',
                'User created but the reset email could not be delivered. '
                . 'Use "Send password reset email" on the edit page once the mailer is reachable.',
            );
        }
    }
}
