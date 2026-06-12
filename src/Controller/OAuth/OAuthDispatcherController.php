<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class OAuthDispatcherController extends AbstractController
{
    private const string FEATURE_FLAG_CONDITION =
        "service('App\\\\Service\\\\Auth\\\\GoogleOAuthFeatureFlag').isEnabled()";

    #[Route(
        '/oauth/google/callback',
        name: 'oauth_google_callback_dispatch',
        methods: ['GET'],
        condition: self::FEATURE_FLAG_CONDITION,
    )]
    public function dispatch(Request $request): RedirectResponse
    {
        $session = $request->getSession();
        $rawPurpose = $session->get('oauth_google_purpose', '');
        $purpose = is_string($rawPurpose) ? $rawPurpose : '';

        $target = match ($purpose) {
            'login'  => 'oauth_google_login_callback',
            'link'   => 'oauth_google_link_callback',
            'invite' => 'oauth_google_invite_callback',
            default  => throw new BadRequestHttpException('Unknown OAuth purpose.'),
        };

        // 307 keeps the request method + query string so the bundle re-reads `code`/`state`.
        return new RedirectResponse(
            $this->generateUrl($target, $request->query->all()),
            Response::HTTP_TEMPORARY_REDIRECT,
        );
    }
}
