<?php

declare(strict_types=1);

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use App\Form\AccountDisplayNameType;
use App\Form\AccountPasswordChangeType;
use App\Form\OrganizerProfileType;
use App\Repository\OrganizerProfileRepository;
use App\Repository\UserIdentityRepository;
use App\Security\Voter\UserIdentityVoter;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    private const int LOGO_PREVIEW_MAX_AGE = 300;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly UserIdentityRepository $identities,
        private readonly OrganizerProfileRepository $organizerProfiles,
        #[Autowire(service: 'brand_logos_storage')]
        private readonly FilesystemOperator $brandLogosStorage,
    ) {
    }

    private function loadOrCreateProfile(User $user): OrganizerProfile
    {
        return $this->organizerProfiles->findOneBy(['user' => $user]) ?? new OrganizerProfile($user);
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

        $profile = $this->loadOrCreateProfile($user);

        $styleForm = $this->createForm(OrganizerProfileType::class, $profile, [
            'action' => $this->generateUrl('account_change_style'),
        ]);

        return $this->render('account/show.html.twig', [
            'user' => $user,
            'identities' => $user->getIdentities(),
            'passwordForm' => $passwordForm,
            'displayNameForm' => $displayNameForm,
            'styleForm' => $styleForm,
            'brandLogoSet' => $profile->getBrandLogoFilename() !== null,
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

    #[Route('/account/style', name: 'account_change_style', methods: ['POST'])]
    public function changeStyle(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $profile = $this->loadOrCreateProfile($user);

        $form = $this->createForm(OrganizerProfileType::class, $profile);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Styling update failed — check the form.');

            return $this->redirectToRoute('account_show');
        }

        if ($profile->getId() === null) {
            $this->em->persist($profile);
        }

        $this->em->flush();

        $this->addFlash('success', 'Styling defaults updated.');

        return $this->redirectToRoute('account_show');
    }

    #[Route('/account/brand-logo', name: 'account_brand_logo', methods: ['GET'])]
    public function brandLogo(): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $profile = $this->loadOrCreateProfile($user);

        $filename = $profile->getBrandLogoFilename();
        if ($filename === null) {
            throw $this->createNotFoundException();
        }

        try {
            $contents = $this->brandLogosStorage->read($filename);
        } catch (FilesystemException) {
            throw $this->createNotFoundException();
        }

        $response = new Response($contents);
        $response->headers->set(
            'Content-Type',
            str_ends_with(strtolower($filename), '.png') ? 'image/png' : 'image/jpeg',
        );
        $response->headers->set('Cache-Control', 'private, max-age=' . self::LOGO_PREVIEW_MAX_AGE);

        return $response;
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
