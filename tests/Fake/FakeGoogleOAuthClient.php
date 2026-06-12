<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\Auth\GoogleOAuthClient;
use App\Service\Auth\GoogleUserData;
use App\Service\Auth\OAuthFailure;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Test-only fake. Tests configure the next response via setNextUserData() or setNextFailure().
 */
final class FakeGoogleOAuthClient implements GoogleOAuthClient
{
    private ?GoogleUserData $nextUserData = null;

    private ?OAuthFailure $nextFailure = null;

    public string $lastPurpose = '';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function setNextUserData(GoogleUserData $data): void
    {
        $this->nextUserData = $data;
        $this->nextFailure = null;
    }

    public function setNextFailure(OAuthFailure $failure): void
    {
        $this->nextFailure = $failure;
        $this->nextUserData = null;
    }

    public function redirectToProvider(string $purpose): RedirectResponse
    {
        $this->lastPurpose = $purpose;
        $session = $this->requestStack->getSession();
        $session->set('oauth_google_purpose', $purpose);
        return new RedirectResponse('https://google.test/oauth/authorize?purpose=' . $purpose);
    }

    public function fetchUserDataFromCallback(Request $request): GoogleUserData
    {
        if ($this->nextFailure instanceof OAuthFailure) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;
            throw $failure;
        }

        if (!$this->nextUserData instanceof GoogleUserData) {
            throw new RuntimeException('FakeGoogleOAuthClient: no next response configured.');
        }

        $data = $this->nextUserData;
        $this->nextUserData = null;
        return $data;
    }
}
