<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

interface GoogleOAuthClient
{
    /**
     * Build the redirect URL for `purpose` ∈ {'login','link','invite'} and return a RedirectResponse.
     * Implementations MUST also stash the purpose in the session so the single callback URL can dispatch.
     */
    public function redirectToProvider(string $purpose): RedirectResponse;

    /**
     * Complete the OAuth dance and return Google's verified user data.
     *
     * @throws OAuthFailure if state verification fails, the access-token exchange fails,
     *                      or Google returns malformed user info.
     */
    public function fetchUserDataFromCallback(Request $request): GoogleUserData;
}
