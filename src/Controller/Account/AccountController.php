<?php

declare(strict_types=1);

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Entity\User;
use App\Form\AccountDisplayNameType;
use App\Form\AccountPasswordChangeType;
use App\Repository\UserIdentityRepository;
use App\Security\Voter\UserIdentityVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly UserIdentityRepository $identities,
    ) {
    }

    #[Route('/account', name: 'account_show', methods: ['GET'])]
    public function show(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $passwordForm = $this->createForm(AccountPasswordChangeType::class, null, [
            'require_current_password' => $user->hasUsablePassword(),
            'action' => $this->generateUrl('account_change_password'),
        ]);

        $displayNameForm = $this->createForm(AccountDisplayNameType::class, null, [
            'action' => $this->generateUrl('account_change_display_name'),
        ]);
        $displayNameForm->get('displayName')->setData($user->getDisplayName());

        return $this->render('account/show.html.twig', [
            'user' => $user,
            'identities' => $user->getIdentities(),
            'passwordForm' => $passwordForm,
            'displayNameForm' => $displayNameForm,
        ]);
    }

    #[Route('/account/password', name: 'account_change_password', methods: ['POST'])]
    public function changePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $requireCurrent = $user->hasUsablePassword();

        $form = $this->createForm(AccountPasswordChangeType::class, null, [
            'require_current_password' => $requireCurrent,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Password change failed — check the form.');
            return $this->redirectToRoute('account_show');
        }

        if ($requireCurrent) {
            $currentField = $form->get('currentPassword');
            $current = $currentField->getData();
            if (!is_string($current) || !$this->passwordHasher->isPasswordValid($user, $current)) {
                $this->addFlash('error', 'Current password is incorrect.');
                return $this->redirectToRoute('account_show');
            }
        }

        $newField = $form->get('newPassword');
        $new = $newField->getData();
        if (!is_string($new) || $new === '') {
            $this->addFlash('error', 'New password is required.');
            return $this->redirectToRoute('account_show');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $new));
        $this->em->flush();

        $this->logger->info('account.password_changed', ['user_id' => $user->getId()]);
        $this->addFlash('success', 'Password updated.');
        return $this->redirectToRoute('account_show');
    }

    #[Route('/account/display-name', name: 'account_change_display_name', methods: ['POST'])]
    public function changeDisplayName(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(AccountDisplayNameType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Display name update failed.');
            return $this->redirectToRoute('account_show');
        }

        $displayNameField = $form->get('displayName');
        $newName = $displayNameField->getData();
        if (!is_string($newName) || $newName === '') {
            $this->addFlash('error', 'Display name is required.');
            return $this->redirectToRoute('account_show');
        }

        $user->setDisplayName($newName);
        $this->em->flush();

        $this->logger->info('account.display_name_changed', ['user_id' => $user->getId()]);
        $this->addFlash('success', 'Display name updated.');
        return $this->redirectToRoute('account_show');
    }

    #[Route('/account/identities/{id}/unlink', name: 'account_identity_unlink', methods: ['POST'])]
    public function unlinkIdentity(int $id, Request $request): RedirectResponse
    {
        $identity = $this->identities->find($id);
        if ($identity === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(UserIdentityVoter::UNLINK, $identity);

        $rawToken = $request->request->get('_token', '');
        $csrfToken = is_string($rawToken) ? $rawToken : '';
        if (!$this->isCsrfTokenValid('unlink-identity-' . $id, $csrfToken)) {
            $this->addFlash('error', 'Invalid request.');
            return $this->redirectToRoute('account_show');
        }

        /** @var User $user */
        $user = $this->getUser();
        $user->removeIdentity($identity);

        $this->em->remove($identity);
        $this->em->flush();

        $this->logger->info('oauth.google.unlinked', [
            'user_id' => $user->getId(),
            'identity_id' => $id,
        ]);
        $this->addFlash('success', 'Identity unlinked.');
        return $this->redirectToRoute('account_show');
    }
}
