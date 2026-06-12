<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Service\Auth\GoogleOAuthClient;
use App\Service\Auth\IdentityLinker;
use App\Service\Auth\LoginRefused;
use App\Service\Auth\OAuthFailure;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleLoginController extends AbstractController
{
    private const string FLAG_CONDITION =
        "service('App\\\\Service\\\\Auth\\\\GoogleOAuthFeatureFlag').isEnabled()";

    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly IdentityLinker $linker,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/oauth/google/login',
        name: 'oauth_google_login_start',
        methods: ['GET'],
        condition: self::FLAG_CONDITION,
    )]
    public function start(): RedirectResponse
    {
        return $this->oauth->redirectToProvider('login');
    }

    #[Route(
        '/oauth/google/login/callback',
        name: 'oauth_google_login_callback',
        methods: ['GET'],
        condition: self::FLAG_CONDITION,
    )]
    public function callback(Request $request): RedirectResponse
    {
        try {
            $data = $this->oauth->fetchUserDataFromCallback($request);
        } catch (OAuthFailure $oAuthFailure) {
            $this->logger->notice(
                'oauth.google.login_refused',
                [
                    'reason' => 'oauth_failure',
                    'detail' => $oAuthFailure->getMessage(),
                ]
            );
            $this->addFlash('error', 'Google sign-in failed. Please try again.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $result = $this->linker->resolveLogin($data);
        } catch (LoginRefused $loginRefused) {
            $this->logger->notice(
                'oauth.google.login_refused',
                [
                    'reason' => $loginRefused->reason->value,
                    'google_email' => $data->email,
                ]
            );
            $this->addFlash('error', $loginRefused->reason->userMessage());

            return $this->redirectToRoute('app_login');
        }

        if ($result->wasAutoLinked) {
            $this->logger->info(
                'oauth.google.auto_linked',
                [
                    'user_id' => $result->user->getId(),
                    'google_email' => $data->email,
                ]
            );
        }

        $this->logger->info(
            'oauth.google.login_succeeded',
            [
                'user_id' => $result->user->getId(),
                'path' => $result->wasAutoLinked ? 'auto_linked' : 'known_sub',
            ]
        );

        $this->security->login($result->user, 'form_login', 'main');

        return new RedirectResponse('/admin');
    }
}
