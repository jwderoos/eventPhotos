<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Entity\User;
use App\Service\Auth\GoogleOAuthClient;
use App\Service\Auth\IdentityLinker;
use App\Service\Auth\LinkRefused;
use App\Service\Auth\OAuthFailure;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class GoogleLinkController extends AbstractController
{
    private const string FLAG_CONDITION =
        "service('App\\\\Service\\\\Auth\\\\GoogleOAuthFeatureFlag').isEnabled()";

    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly IdentityLinker $linker,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/oauth/google/link',
        name: 'oauth_google_link_start',
        methods: ['GET'],
        condition: self::FLAG_CONDITION,
    )]
    public function start(): RedirectResponse
    {
        return $this->oauth->redirectToProvider('link');
    }

    #[Route(
        '/oauth/google/link/callback',
        name: 'oauth_google_link_callback',
        methods: ['GET'],
        condition: self::FLAG_CONDITION,
    )]
    public function callback(Request $request): RedirectResponse
    {
        /** @var User $current */
        $current = $this->getUser();

        try {
            $data = $this->oauth->fetchUserDataFromCallback($request);
        } catch (OAuthFailure) {
            $this->logger->notice(
                'oauth.google.link_refused',
                [
                    'user_id' => $current->getId(),
                    'reason' => 'oauth_failure',
                ]
            );
            $this->addFlash('error', 'Google sign-in failed. Please try again.');

            return new RedirectResponse('/account');
        }

        try {
            $identity = $this->linker->linkToCurrentUser($current, $data);
        } catch (LinkRefused $linkRefused) {
            $this->logger->notice(
                'oauth.google.link_refused',
                [
                    'user_id' => $current->getId(),
                    'reason' => $linkRefused->reason->value,
                ]
            );
            $this->addFlash('error', $linkRefused->reason->userMessage());

            return new RedirectResponse('/account');
        }

        $this->logger->info(
            'oauth.google.linked',
            [
                'user_id' => $current->getId(),
                'google_email' => $identity->getEmail(),
            ]
        );
        $this->addFlash('success', 'Google account linked.');

        return new RedirectResponse('/account');
    }
}
