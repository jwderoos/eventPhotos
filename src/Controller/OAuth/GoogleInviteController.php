<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Audit\AuditAction;
use App\Audit\Attribute\Audited;
use App\Entity\User;
use App\Entity\Invitation;
use App\Service\Auth\GoogleOAuthClient;
use App\Service\Auth\IdentityCreator;
use App\Service\Auth\LoginRefused;
use App\Service\Auth\OAuthFailure;
use App\Service\Invitation\InvitationResolver;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleInviteController extends AbstractController
{
    private const string FLAG_CONDITION =
        "service('App\\\\Service\\\\Auth\\\\GoogleOAuthFeatureFlag').isEnabled()";

    private const string SESSION_KEY = 'oauth_google_invite_token';

    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly InvitationResolver $resolver,
        private readonly IdentityCreator $creator,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/oauth/google/invite/{token}',
        name: 'oauth_google_invite_start',
        requirements: ['token' => '[a-f0-9]+\.[a-f0-9]+'],
        methods: ['GET'],
        condition: self::FLAG_CONDITION,
    )]
    public function start(string $token, Request $request): Response
    {
        $invite = $this->resolver->resolveValid($token);
        if (!$invite instanceof Invitation) {
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        $request->getSession()->set(self::SESSION_KEY, $token);
        return $this->oauth->redirectToProvider('invite');
    }

    #[Route(
        '/oauth/google/invite/callback',
        name: 'oauth_google_invite_callback',
        methods: ['GET'],
        condition: self::FLAG_CONDITION,
    )]
    #[Audited(AuditAction::InviteRedeem)]
    public function callback(Request $request): Response
    {
        $rawToken = $request->getSession()->get(self::SESSION_KEY, '');
        $token = is_string($rawToken) ? $rawToken : '';
        if ($token === '') {
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        $invite = $this->resolver->resolveValid($token);
        if (!$invite instanceof Invitation) {
            $request->getSession()->remove(self::SESSION_KEY);
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        try {
            $data = $this->oauth->fetchUserDataFromCallback($request);
        } catch (OAuthFailure) {
            $this->logger->notice('oauth.google.login_refused', ['reason' => 'oauth_failure_invite']);
            $this->addFlash('error', 'Google sign-in failed. Please try again.');
            return new RedirectResponse('/invite/' . $token);
        }

        try {
            $user = $this->creator->createUserFromInvite($invite, $data);
        } catch (LoginRefused $loginRefused) {
            $this->logger->notice('oauth.google.login_refused', [
                'reason' => $loginRefused->reason->value,
                'google_email' => $data->email,
                'invite_id' => $invite->getId(),
            ]);
            $this->addFlash('error', $loginRefused->reason->userMessage());
            return new RedirectResponse('/invite/' . $token);
        }

        if (!$user instanceof User) {
            $this->logger->warning('invite.redeem_failed', [
                'reason' => 'race_lost',
                'invite_id' => $invite->getId(),
            ]);
            return $this->render('public/invitation/invalid.html.twig', [], new Response(null, Response::HTTP_GONE));
        }

        $this->logger->info('oauth.google.invite_redeemed', [
            'user_id' => $user->getId(),
            'invite_id' => $invite->getId(),
            'google_email' => $data->email,
        ]);

        $request->getSession()->remove(self::SESSION_KEY);
        $this->security->login($user, 'form_login', 'main');
        return new RedirectResponse('/admin');
    }
}
