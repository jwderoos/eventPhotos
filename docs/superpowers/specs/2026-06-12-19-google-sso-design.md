# Google SSO (login always, signup gated by invite)

**Issue:** [#19](https://github.com/jwderoos/eventPhotos/issues/19)
**Date:** 2026-06-12

## Background

Today the app has one authentication path: email + password via Symfony's `form_login` firewall, with accounts created by `app:create-user` (admin CLI) or by redeeming a single-use invite (#31). Users want "Sign in with Google" — both as an alternative to typing a password and as an alternative way to redeem an invite.

This feature is **independent of #18** (public organizer self-signup). Google never creates an account on the public surface. New accounts only come into being through the existing admin-create or invite-redeem paths. Google is purely a login method and an alternative redemption path inside an invite.

The data model is designed so additional providers (Apple, Steam, Amazon…) can be added later without schema changes — only one provider, Google, is implemented in this ticket.

## Goals & non-goals

**In scope (v1)**
- "Sign in with Google" button on `/login`, with the resolution algorithm in [Login resolution](#login-resolution).
- "Sign in with Google" button on `/invite/{token}` that creates a new account from Google identity data in a single transaction, marking the invite used.
- `/account` profile page with linked identities (link/unlink Google), change password, change display name.
- Feature flag: with empty `GOOGLE_OAUTH_CLIENT_ID`, no Google UI appears anywhere and `/oauth/google/*` returns 404. Devs without credentials still get a fully working app.
- `email_verified=false` from Google is always refused.
- Logging at every success and refusal, matching the existing `invite.*` shape.

**Out of scope (deferred follow-ups)**
- Domain restriction (e.g., only `@company.com`).
- `_target_path` propagation through the OAuth dance — Google login always redirects to `/admin`, like `form_login`.
- Multiple Google accounts per user (unique `(user_id, provider)`).
- Other providers — data model supports them, no controllers wired.
- Public Google signup independent of an invite (deliberately not done — keeps this ticket independent of #18).
- Re-issuing invites bound to a specific email at creation time.

## Operational prerequisites

Per environment (dev / staging / prod) you need one **Google OAuth 2.0 Web Client**:

1. **Google Cloud project** — one is enough. Free tier suffices; no billing required.
2. **OAuth consent screen** — User type **External** (or Internal if Workspace-only). Scopes are just `openid`, `email`, `profile` — basic scopes, so **no Google verification review needed**. While in "Testing" status, each tester's Google address must be on the test-users list (cap: 100). Promoting to "Production" is self-service for basic-scope apps.
3. **OAuth 2.0 Client ID** (type **Web application**):
   - **Authorized redirect URI** (exact match, per environment):
     - `http://localhost:8080/oauth/google/callback` (dev)
     - `https://<staging-host>/oauth/google/callback`
     - `https://<prod-host>/oauth/google/callback`
   - No JavaScript origins needed — the dance is fully server-side.
   - Google issues a **Client ID** and **Client Secret**. They are configured per environment via `GOOGLE_OAUTH_CLIENT_ID` / `GOOGLE_OAUTH_CLIENT_SECRET` (see [Configuration](#configuration)).

Not required: domain ownership verification, paid GCP, Workspace, app review.

Cost: zero — basic identity scopes have no per-request pricing.

Local-dev caveat: `http://localhost:8080` is one of the only HTTP redirect URIs Google allows; tunneled URLs (ngrok etc.) must be HTTPS.

## Feature flag (load-bearing)

The application MUST be fully usable when `GOOGLE_OAUTH_CLIENT_ID` is empty. Gating is enforced at three layers, all backed by the same `App\Service\Auth\GoogleOAuthFeatureFlag::isEnabled()`:

1. **Routes** — every `/oauth/google/*` route carries `condition: "service('App\\\\Service\\\\Auth\\\\GoogleOAuthFeatureFlag').isEnabled()"`. Symfony returns 404 at routing time, before the controller is constructed. An attacker probing for OAuth endpoints sees the same 404 as any unknown route.
2. **Templates** — a Twig function `google_oauth_enabled()` wraps every Google button on `/login`, `/invite/{token}`, and `/account`. With the flag off, the button is not rendered.
3. **Services** — `GoogleOAuthClient` only ever touches the knpu bundle inside its own methods, so an empty-credential config does not break boot. If the knpu bundle itself throws at compile time when credentials are empty (to be verified during implementation), the bundle's service definitions are conditionalised on the env var being set (e.g. via a compiler-pass or `when@…` block); the route + template gates then ensure those services are never resolved with the flag off.

Acceptance test: with `GOOGLE_OAUTH_CLIENT_ID=` empty, the existing functional tests for `/login` and `/invite/{token}` pass, no Google UI is rendered, and `GET /oauth/google/login` returns 404.

## Architecture overview

Two firewalls remain as-is; no Symfony Authenticator. Controllers drive a thin OAuth client and delegate domain decisions to focused services. Each unit can be understood and tested in isolation.

```
Controller ─► GoogleOAuthClient ─► (OAuth dance) ─► GoogleUserData
                                                          │
Controller ─► IdentityLinker / IdentityCreator ◄──────────┘
                  │
                  ▼
              UserIdentity (provider, subject, user_id)
                  │
                  ▼
              Security::login($user, 'form_login', 'main')
              (same pattern as InvitationRedemptionController::submit)
```

Five units:

1. **`App\Entity\UserIdentity`** — persistence; pure data + constructor invariants.
2. **`App\Service\Auth\GoogleOAuthFeatureFlag`** — credential-presence check; trivial, but the single source of truth for "is Google wired".
3. **`App\Service\Auth\GoogleOAuthClient`** (interface + default impl) — wraps `knpuniversity/oauth2-client-bundle`. The interface exists so functional tests bind a fake — no real network in any test.
4. **`App\Service\Auth\IdentityLinker`** — the [login resolution algorithm](#login-resolution) and the link rules; throws `LoginRefused` / `LinkRefused` carrying an `OAuthRefusalReason` enum case so the log payload and flash message stay aligned.
5. **`App\Service\Auth\IdentityCreator`** — creates a `User` + `UserIdentity` from a Google identity inside an invite redemption, reusing the existing `EntityManager::wrapInTransaction` + `PESSIMISTIC_WRITE` pattern.

Supporting refactor:

- **`App\Service\Invitation\InvitationResolver`** — extracted from `InvitationRedemptionController::resolveValidInvite()` so both the password-redeem and Google-redeem controllers share one implementation.

## Data model

New entity `App\Entity\UserIdentity` → table `user_identities`:

| column      | type                       | notes                                             |
|-------------|----------------------------|---------------------------------------------------|
| id          | int, PK, identity          |                                                   |
| user_id     | FK → users, NOT NULL       | cascade delete, indexed                           |
| provider    | string(32)                 | Doctrine maps `App\Enum\AuthProvider` enum → value |
| subject     | string(191)                | provider's stable subject claim (Google `sub`)    |
| email       | string(180), nullable      | last-seen email from provider; informational only |
| linked_at   | datetimetz_immutable       | set on persist                                    |

Constraints (Doctrine-generated names):
- `UNIQUE (provider, subject)` — one provider account binds to at most one app user.
- `UNIQUE (user_id, provider)` — one app user has at most one identity per provider.

`User` additions (only):
- `#[OneToMany(targetEntity: UserIdentity::class, mappedBy: 'user', cascade: ['persist','remove'], orphanRemoval: true)] private Collection $identities;`
- `User::hasIdentityFor(AuthProvider): bool`
- `User::getIdentityFor(AuthProvider): ?UserIdentity`

New enum `App\Enum\AuthProvider { case Google; }` — Doctrine-mapped as string. Single-case for v1; the file exists so adding a provider is a one-line change with type-checking everywhere it's used.

Migration generated via `bin/console doctrine:migrations:diff` — **never hand-written** (per CLAUDE.md, hand-written constraint names drift from Doctrine's auto-generated hashes). `doctrine:schema:validate --env=test` must pass after migration; this is already a CI gate.

## Routes

```
GET  /oauth/google/login              anonymous              GoogleLoginController::start
GET  /oauth/google/login/callback     anonymous              GoogleLoginController::callback
GET  /oauth/google/link               ROLE_USER              GoogleLinkController::start
GET  /oauth/google/link/callback      ROLE_USER              GoogleLinkController::callback
GET  /oauth/google/invite/{token}     anonymous              GoogleInviteController::start
GET  /oauth/google/invite/callback    anonymous              GoogleInviteController::callback
GET  /oauth/google/callback           anonymous              OAuthDispatcherController::dispatch
GET  /account                         ROLE_USER              AccountController::show
POST /account/password                ROLE_USER              AccountController::changePassword
POST /account/display-name            ROLE_USER              AccountController::changeDisplayName
POST /account/identities/{id}/unlink  ROLE_USER + voter      AccountController::unlinkIdentity
```

All `/oauth/google/*` routes carry the feature-flag route condition.

**Single redirect URI per environment.** Google's redirect URI must match exactly. To avoid registering three URIs per environment, we register one (`/oauth/google/callback`) and use `OAuthDispatcherController` to read a session-stashed `purpose` (`login` / `link` / `invite`) and forward to the matching purpose-specific callback action. The alternative — three Google-side URIs per environment — is more setup friction and a third more strings to keep in sync.

`AccountController` lives in a new namespace `App\Controller\Account\` (sibling of `Admin` and `Public`). Access-control adds `- { path: ^/account, roles: ROLE_USER }` to `security.yaml`, ordered after the existing `/admin` entry.

## Login resolution

The load-bearing logic. `IdentityLinker::resolveLogin(GoogleUserData $g): User`:

```
if !g.emailVerified                  → refuse(EMAIL_NOT_VERIFIED)

identity = UserIdentityRepo.findOneBy(provider=Google, subject=g.subject)
if identity                          → return identity.user                  // path A: known sub

user = UserRepo.findOneBy(email=g.email)
if !user                             → refuse(NO_ACCOUNT)                    // keeps #19 independent of #18

if user.hasIdentityFor(Google)       → refuse(EMAIL_BOUND_TO_OTHER_GOOGLE)   // defense in depth; see note

createIdentity(user, g) within transaction
return user                                                                   // path B: auto-link silently
```

Notes:
- **Path A** succeeds even if the user's Google email changed since first linking (subject is stable).
- **Path B** is the silent auto-link. The security argument: anyone who controls `g.email`'s inbox already controls the existing user account via password-reset; allowing them to click "Sign in with Google" once changes friction, not the attack surface.
- The `EMAIL_BOUND_TO_OTHER_GOOGLE` branch is theoretically unreachable (if the user already had a Google identity, the `(provider, subject)` lookup at the top would have returned a different user) — kept as defense in depth so a bug elsewhere can't silently rebind a user's Google identity.
- `refuse(reason)` throws `LoginRefused($reason: OAuthRefusalReason)`. The controller catches it, logs `oauth.google.login_refused` with `reason`, flashes `$reason->userMessage()`, and redirects to `/login`.

`IdentityLinker::linkToCurrentUser(User $current, GoogleUserData $g): UserIdentity`:

```
if !g.emailVerified                                    → refuse(EMAIL_NOT_VERIFIED)
if current.hasIdentityFor(Google)                      → refuse(ALREADY_LINKED_TO_CURRENT)
if UserIdentityRepo.exists(provider=Google, subject=g.subject) → refuse(BOUND_TO_OTHER_USER)
createIdentity(current, g)
log oauth.google.linked
return identity
```

The Google email does **not** need to match the user's app email — `jane@personal.com` linking `jane@work.com` is fine.

## Invite redemption via Google

`IdentityCreator::createUserFromInvite(Invitation $invite, GoogleUserData $g): User`:

```
EntityManager::wrapInTransaction(function () use ($invite, $g) {
    invite = InvitationResolver::resolveValid($invite.token)   // re-fetch with PESSIMISTIC_WRITE lock
    if !g.emailVerified                            → refuse(EMAIL_NOT_VERIFIED)
    if UserRepo.findOneBy(email=g.email)           → refuse(EMAIL_TAKEN)   // mirrors password-flow check
    user = new User(g.email, g.displayName, $randomBcryptHash, $invite.role)
    identity = new UserIdentity(user, Google, g.subject, g.email)
    em.persist(user); em.persist(identity)
    invite.markUsed()
    return user
})
```

The same `PESSIMISTIC_WRITE` race protection as `InvitationRedemptionController::submit`: two concurrent invite-Google submissions race; one wins, the other gets `INVITE_ALREADY_USED`. A functional test exercises this with the same pattern as the password redemption test.

The random bcrypt hash is *valid* (passes `PasswordHasher::isPasswordValid` against no input) but unknown to the user. To set a password, they use the password-reset flow via their verified email.

## Unlink

`POST /account/identities/{id}/unlink` with CSRF. Voter `UserIdentityVoter::UNLINK` grants if `user === identity.user || isGranted('ROLE_ADMIN')`.

**No "would this leave the user without a usable password" check.** Rationale: every user has a verified email, so password-reset is always an available recovery path. The check the original spec proposed (`password === ''`) would never trigger anyway, because no flow stores an empty password. Removing the check eliminates dead code and a confusing failure mode.

After unlink: log `oauth.google.unlinked`, flash success, redirect to `/account`.

## /account page

Server-rendered (Twig + Tailwind), three sections:

1. **Linked identities** — table of `provider | email | linked_at | [Unlink]`. Below: `[Link Google]` button if Google not yet linked AND feature flag on.
2. **Change password** — when `User::hasUsablePassword()` (currently: password column is a non-empty hash, which is always true given the data model), form has *current password*, *new password*, *confirm new password*. When false, form has *new password*, *confirm new password* and is labelled "Set password". Submission goes to `POST /account/password`. Current-password verification uses the existing `UserPasswordHasher`.
3. **Display name** — single field + save → `POST /account/display-name`.

The `hasUsablePassword()` accessor exists for future-proofing (e.g., if a "passwordless" path is ever added). Today it returns `true` for every persisted user.

## Configuration

**Composer:**
```
composer require knpuniversity/oauth2-client-bundle league/oauth2-google
```

**`config/packages/knpu_oauth2_client.yaml`** (new):
```yaml
knpu_oauth2_client:
    clients:
        google:
            type: google
            client_id: '%env(default::GOOGLE_OAUTH_CLIENT_ID)%'
            client_secret: '%env(default::GOOGLE_OAUTH_CLIENT_SECRET)%'
            redirect_route: oauth_google_callback_dispatch
            redirect_params: {}
```

**`.env`** additions:
```
###> knpuniversity/oauth2-client-bundle ###
GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_CLIENT_SECRET=
###< knpuniversity/oauth2-client-bundle ###
```

`.env.local` (dev), env vars in prod (TrueNAS) hold real values. Empty in `.env` means the feature flag is off by default — fresh checkouts work without Google credentials.

**`config/packages/security.yaml`** access_control prepends (above the `/admin` rule it sits below in priority, after generic patterns):
```yaml
- { path: ^/account, roles: ROLE_USER }
```

**`config/services_test.yaml`** binds the fake (see [Testing](#testing-strategy)):
```yaml
services:
    App\Service\Auth\GoogleOAuthClient: '@App\Tests\Fake\FakeGoogleOAuthClient'
    App\Tests\Fake\FakeGoogleOAuthClient:
        public: true
```

## Logging

Match the existing `invite.*` log shape (level INFO for success, NOTICE for refusal, structured context including `user_id` when known):

- `oauth.google.login_succeeded` `{user_id, path: 'known_sub' | 'auto_linked'}`
- `oauth.google.auto_linked` `{user_id, google_email}` (additionally, alongside `login_succeeded` for path B)
- `oauth.google.login_refused` `{reason, google_email}`
- `oauth.google.linked` `{user_id, google_email}`
- `oauth.google.unlinked` `{user_id, identity_id}`
- `oauth.google.invite_redeemed` `{user_id, invite_id, google_email}`
- `oauth.google.link_refused` `{user_id, reason}`

`reason` values are the `OAuthRefusalReason` enum case names (lowercase snake_case) so log queries can pivot on a known finite set.

## Testing strategy

**Unit:**
- `IdentityLinker` against in-memory fakes: every refusal reason and both success paths in `resolveLogin`; every refusal reason and success in `linkToCurrentUser`.
- `User::hasIdentityFor` / `getIdentityFor`.
- `GoogleOAuthFeatureFlag::isEnabled()` — empty, whitespace, set.

**Functional** (`WebTestCase` + `dama/doctrine-test-bundle`):
- Login: 8 scenarios (new sub / existing sub / no user / unverified email / already-linked-different-google / Google email differs from app email / etc.) — each asserts session-logged-in user, flash, log entries.
- Link: 4 scenarios (success, sub bound elsewhere, already linked, unverified email).
- Invite-Google: 3 scenarios (success, email taken, invite already used).
- Unlink: success + voter refusal (other user; admin success).
- `/account` password and display-name change: happy paths + current-password mismatch.
- Feature-flag-off: login page renders without Google button; `/oauth/google/login` returns 404; existing `/login` and `/invite/{token}` tests stay green.

**Mocking:** `GoogleOAuthClient` is bound to `FakeGoogleOAuthClient` in `services_test.yaml`. Tests configure the fake's next-call return value (a `GoogleUserData` or thrown `OAuthFailure`) before invoking the controller. **No real network in any test.**

**Race:** invite-Google redemption tested with the same concurrent-submission pattern as the password redemption — one wins, the other gets `INVITE_ALREADY_USED`.

## Acceptance criteria

- [ ] `composer.json` adds `knpuniversity/oauth2-client-bundle` and `league/oauth2-google`.
- [ ] Migration generated via `doctrine:migrations:diff` creates `user_identities` with the two unique constraints. `doctrine:schema:validate --env=test` is clean after migration.
- [ ] `GoogleOAuthFeatureFlag::isEnabled()` returns `false` when `GOOGLE_OAUTH_CLIENT_ID` is empty; all `/oauth/google/*` routes return 404 in that state; login and invite templates do not render the Google button.
- [ ] Login: existing user with matching verified email is auto-linked on first Google sign-in and is logged in.
- [ ] Login: subsequent Google sign-ins resolve by `(provider, subject)` and succeed even if the user's Google email changes.
- [ ] Login: refused with a clear message when no user exists with Google's email.
- [ ] Login / link / invite: refused when Google returns `email_verified=false`.
- [ ] Link: a logged-in user can link a Google account whose email differs from their app email.
- [ ] Link: refused when Google's `sub` is already linked to another user, and when the current user already has a Google identity.
- [ ] Unlink: always succeeds when voter grants; no password-state check.
- [ ] Invite redemption via Google creates a `User` + `UserIdentity` and marks the invite used in a single transaction; race-tested.
- [ ] `/account` page shows linked identities, allows linking/unlinking Google, allows changing password and display name.
- [ ] Functional tests cover every scenario above by binding `FakeGoogleOAuthClient` in `services_test.yaml`; no real network.
- [ ] Logs emitted at every success/refusal with the names listed in [Logging](#logging).
