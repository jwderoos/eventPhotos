<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Invitation;
use App\Entity\User;
use App\Form\InvitationRedeemType;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Service\Invitation\InvitationTokenService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

final class InvitationRedemptionController extends AbstractController
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly InvitationTokenService $tokens,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Security $security,
    ) {
    }

    #[Route(
        '/invite/{token}',
        name: 'public_invite_redeem',
        requirements: ['token' => '[a-f0-9]+\.[a-f0-9]+'],
        methods: ['GET'],
    )]
    public function show(string $token): Response
    {
        if ($this->getUser() instanceof UserInterface) {
            return $this->render('public/invitation/already_signed_in.html.twig');
        }

        $invite = $this->resolveValidInvite($token);
        if (!$invite instanceof Invitation) {
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        $form = $this->createForm(InvitationRedeemType::class);
        return $this->render('public/invitation/redeem.html.twig', [
            'form'  => $form,
            'token' => $token,
        ]);
    }

    #[Route(
        '/invite/{token}',
        name: 'public_invite_redeem_submit',
        requirements: ['token' => '[a-f0-9]+\.[a-f0-9]+'],
        methods: ['POST'],
    )]
    public function submit(string $token, Request $request): Response
    {
        if ($this->getUser() instanceof UserInterface) {
            return $this->render('public/invitation/already_signed_in.html.twig');
        }

        $invite = $this->resolveValidInvite($token);
        if (!$invite instanceof Invitation) {
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        $form = $this->createForm(InvitationRedeemType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('public/invitation/redeem.html.twig', [
                'form'  => $form,
                'token' => $token,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        /** @var array{email: string, displayName: string} $data */
        $data = $form->getData();
        $email = $data['email'];
        $displayName = $data['displayName'];
        $plainPassword = $form->get('password')->getData();
        if (!is_string($plainPassword) || $plainPassword === '') {
            $form->get('password')->addError(new FormError('Password is required.'));
            return $this->render('public/invitation/redeem.html.twig', [
                'form'  => $form,
                'token' => $token,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if ($this->users->findOneByEmail($email) instanceof User) {
            $form->get('email')->addError(new FormError(
                'An account already exists for this email — sign in or reset your password.',
            ));
            return $this->render('public/invitation/redeem.html.twig', [
                'form'  => $form,
                'token' => $token,
            ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $newUser = $this->em->wrapInTransaction(function () use ($invite, $email, $displayName, $plainPassword): ?User {
            $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($invite);

            if (!$invite->isPending()) {
                return null;
            }

            $user = new User($email, $displayName);
            $user->addRole($invite->getRole());
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

            $this->em->persist($user);
            $this->em->flush();

            $invite->markUsed($user, $email);
            $this->em->flush();

            return $user;
        });

        if (!$newUser instanceof User) {
            $this->logger->warning('invite.redeem_failed', [
                'reason'    => 'race_lost',
                'invite_id' => $invite->getId(),
            ]);
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        $this->logger->info('invite.redeemed', [
            'invite_id'     => $invite->getId(),
            'new_user_id'   => $newUser->getId(),
            'used_by_email' => $newUser->getEmail(),
        ]);

        $this->security->login($newUser, 'form_login', 'main');
        return new RedirectResponse($this->generateUrl('admin_dashboard'));
    }

    private function resolveValidInvite(string $token): ?Invitation
    {
        $parsed = $this->tokens->parse($token);
        if ($parsed === null) {
            $this->logger->warning('invite.redeem_failed', ['reason' => 'malformed']);
            return null;
        }

        $invite = $this->invitations->findBySelector($parsed['selector']);
        if (!$invite instanceof Invitation) {
            $this->logger->warning('invite.redeem_failed', [
                'reason'          => 'unknown',
                'selector_prefix' => substr($parsed['selector'], 0, 8),
            ]);
            return null;
        }

        if (!$this->tokens->verify($invite->getHashedVerifier(), $parsed['verifier'])) {
            $this->logger->warning('invite.redeem_failed', [
                'reason'          => 'verifier_mismatch',
                'invite_id'       => $invite->getId(),
                'selector_prefix' => substr($parsed['selector'], 0, 8),
            ]);
            return null;
        }

        if (!$invite->isPending()) {
            $this->logger->warning('invite.redeem_failed', [
                'reason'    => $invite->status()->value,
                'invite_id' => $invite->getId(),
            ]);
            return null;
        }

        return $invite;
    }
}
