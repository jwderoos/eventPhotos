<?php

declare(strict_types=1);

namespace App\Service\Auth;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GoogleClient;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

final readonly class RealGoogleOAuthClient implements GoogleOAuthClient
{
    private const string SESSION_PURPOSE_KEY = 'oauth_google_purpose';

    public function __construct(
        private ClientRegistry $clients,
        private RequestStack $requestStack,
    ) {
    }

    public function redirectToProvider(string $purpose): RedirectResponse
    {
        $this->requestStack->getSession()->set(self::SESSION_PURPOSE_KEY, $purpose);

        /** @var GoogleClient $client */
        $client = $this->clients->getClient('google');
        return $client->redirect(['email', 'profile'], []);
    }

    public function fetchUserDataFromCallback(Request $request): GoogleUserData
    {
        /** @var GoogleClient $client */
        $client = $this->clients->getClient('google');

        try {
            $rawToken = $client->getAccessToken();
            if (!$rawToken instanceof AccessToken) {
                throw new OAuthFailure('Unexpected token type returned by Google OAuth client.');
            }

            /** @var GoogleUser $user */
            $user = $client->fetchUserFromToken($rawToken);
        } catch (OAuthFailure $e) {
            throw $e;
        } catch (IdentityProviderException $e) {
            throw new OAuthFailure('Google OAuth failed: ' . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new OAuthFailure('Google OAuth client error: ' . $e->getMessage(), 0, $e);
        }

        $payload = $user->toArray();
        $verified = ($payload['email_verified'] ?? false) === true;

        $subject = $payload['sub'] ?? null;
        if (!is_string($subject) || $subject === '') {
            throw new OAuthFailure('Google OAuth response missing required subject claim.');
        }

        return new GoogleUserData(
            subject: $subject,
            email: (string) $user->getEmail(),
            emailVerified: $verified,
            displayName: $user->getName(),
        );
    }
}
