# Password Reset Flow — Design

**Issue:** [#17 — Password reset flow](https://github.com/jwderoos/eventPhotos/issues/17)
**Status:** Design approved, ready for implementation plan.

## Goal

Give a user who has forgotten their password a self-service way to set a new one: request a single-use, time-limited link by email, follow it, and choose a new password. The same machinery must be reusable by the future admin-triggered reset (issue #16) and must work for Google-SSO accounts that want to add a password later (issue #19).

## Why now

The foundation document lists password reset as a prerequisite for the rest of the auth roadmap. Three downstream issues depend on it:

- **#16 Admin User CRUD + first-run bootstrap** — admins trigger resets instead of editing passwords directly.
- **#18 Organizer self-signup** — a signup that can't recover passwords is a half-feature.
- **#19 Google SSO** — Google-created accounts use the reset flow to set a password for email+password login.

Building this first means each downstream issue can call the same primitive instead of inventing its own.

## Architecture

Use the [`symfonycasts/reset-password-bundle`](https://github.com/SymfonyCasts/reset-password-bundle) (`^1.x`). The bundle owns the token lifecycle (selector + hashed-verifier scheme — the usable token never lives in the database), single-use enforcement, expiry, and per-email throttling. Our code is three thin controllers + two form types + one Doctrine entity that the bundle's traits do most of the work for.

Email is sent **synchronously** through `MailerInterface`. Latency on `/reset-password` POST is acceptable (sub-second through Mailpit / SMTP) and the operation is rare. This keeps the reset path independent of the Messenger worker.

### Components

- `App\Entity\ResetPasswordRequest` — Doctrine entity implementing `SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface`.
- `App\Repository\ResetPasswordRequestRepository` — extends `ResetPasswordRequestRepositoryTrait`, provides the bundle the persistence hooks it needs.
- `App\Controller\ResetPasswordController` — three actions: request form, check-email confirmation, reset form.
- `App\Form\RequestPasswordResetFormType` — email field.
- `App\Form\ChangePasswordFormType` — repeated password field, `mapped: false`.
- `templates/reset_password/{request,check_email,reset}.html.twig` — UI.
- `templates/reset_password/email.html.twig` — the reset email.
- One Doctrine migration created with `bin/console doctrine:migrations:diff` (per [CLAUDE.md](../../../CLAUDE.md) migration rule — never hand-written).

### Configuration

`config/packages/reset_password.yaml` (the bundle's default recipe shape):

```yaml
symfonycasts_reset_password:
    request_password_repository: App\Repository\ResetPasswordRequestRepository
    lifetime: 3600          # 1h token TTL
    throttle_limit: 3600    # 1h per-email throttle
```

Both are bundle defaults; setting them explicitly documents the policy.

## Routes and UX flow

| Route | Method | Purpose |
|---|---|---|
| `/reset-password` | GET, POST | Form: enter email. POST always returns the same confirmation, regardless of whether the email matches an account. |
| `/reset-password/check-email` | GET | Confirmation page: "If an account exists, an email is on its way." |
| `/reset-password/reset/{token}` | GET, POST | Form: new password + repeat. POST hashes the password, deletes the reset request, redirects to `/login` with a success flash. |

All three are declared `PUBLIC_ACCESS` in `config/packages/security.yaml`'s `access_control` (a new entry above the catch-all). The `/login` template gets a "Forgot your password?" link to `/reset-password`.

## Data model

```
ResetPasswordRequest
├── id            : int, PK
├── user_id       : int, FK → users.id, ON DELETE CASCADE, NOT NULL
├── selector      : string(20), NOT NULL
├── hashed_token  : string(100), NOT NULL
├── requested_at  : datetime_immutable, NOT NULL
└── expires_at    : datetime_immutable, NOT NULL
```

Indexes: PK on `id`. Doctrine auto-generated FK index on `user_id`. No additional manual indexes — the bundle queries by `selector` but only after fetching candidate rows via `requested_at` / per-user lookup; the row count stays tiny so a dedicated index is overkill.

Cascade delete on the FK ensures that deleting a `User` (per #16's eventual delete action) cleans up any in-flight reset requests.

## Forms and validation

### RequestPasswordResetFormType

- `email` — `EmailType`, constraints: `NotBlank`, `Email(mode: html5)`.
- CSRF on by default.

### ChangePasswordFormType

- `plainPassword` — `RepeatedType<PasswordType>` (`first_options` / `second_options`), `mapped: false`.
- Constraints on the underlying scalar: `NotBlank`, `Length(min: 12)`.
- `invalid_message: "The password fields must match."`
- CSRF on by default.

The 12-character minimum is the only complexity rule — modern NIST guidance favors length over symbol mandates, and the existing `app:create-user` command enforces nothing today, so this is the policy baseline going forward.

## Controller behavior

### `request` action (GET, POST `/reset-password`)

1. Build and handle `RequestPasswordResetFormType`.
2. On submit: look up `User` by email.
   - If user not found: silently proceed to step 4 (no row created, no email sent).
   - If user found: call `ResetPasswordHelperInterface::generateResetToken($user)`. Catch `ResetPasswordExceptionInterface`:
     - `TooManyPasswordRequestsException` — swallow, proceed to step 4 (do not leak throttling state).
     - Other exceptions — re-throw; let the error page surface.
3. Compose the email via `TemplatedEmail` pointing to `templates/reset_password/email.html.twig`, with `resetToken` and `tokenLifetime` context. Send synchronously via `MailerInterface`.
4. Redirect to `/reset-password/check-email`.

### `checkEmail` action (GET `/reset-password/check-email`)

Renders a static page. Reads `getTokenObjectFromSession()` only to compute `tokenLifetime` for the message; if absent, falls back to the configured lifetime constant. No PII rendered.

### `reset` action (GET, POST `/reset-password/reset/{token?}`)

1. If `{token}` is in the URL: store it in the session via `storeTokenInSession()` and redirect to `/reset-password/reset` (without the token in the URL) — bundle pattern to keep the token out of browser history.
2. Pull the token from session. If missing: 404.
3. Call `ResetPasswordHelperInterface::validateTokenAndFetchUser($token)`. On `ResetPasswordExceptionInterface`: flash error, redirect to `/reset-password`.
4. Build and handle `ChangePasswordFormType`.
5. On valid submit:
   - `ResetPasswordHelperInterface::removeResetRequest($token)` — single-use enforcement.
   - Hash the new password (`UserPasswordHasherInterface::hashPassword`) and assign via `User::setPassword`.
   - Persist + flush.
   - Flash success, redirect to `/login`.

## Security considerations

1. **Account enumeration** — the `request` action returns the same response (302 → check-email page) whether the email exists or not, and whether throttling fired or not.
2. **Throttling** — bundle's per-email throttle (`throttle_limit: 3600`) blocks repeat requests within an hour. Same generic UX on throttle.
3. **Token model** — selector lives in the URL, verifier is hashed in the DB. A database read alone cannot produce a usable token.
4. **Single-use** — `removeResetRequest()` is called on successful password change, which deletes the DB row. A reused link after success fails validation. (A token redeemed once but never completed remains valid until `expires_at`; that's acceptable and matches the bundle's documented semantics.)
5. **Token in URL** — moved to session on first GET, then the URL is replaced with a clean `/reset-password/reset` — keeps tokens out of browser history, referrer headers, and server access logs.
6. **CSRF** — both forms have CSRF tokens (Symfony Forms default).
7. **Google-SSO accounts** — `User::$password` defaults to `''`, so a passwordless user can request a reset and set a password to enable email+password login. No special-casing.
8. **Logout-on-reset** — the firewall does not auto-invalidate other sessions for the same user; not in scope. (Session fixation on the resetter's own browser is already mitigated because the form login starts a fresh session after redirect to `/login`.)

## Reusability for #16 (admin-triggered reset)

Admins triggering a reset is **not** built here. The reusable seam is `ResetPasswordHelperInterface::generateResetToken(User $user)` — issue #16 will inject the helper, call it for the target user, and send the same email template. No new service or wrapper is introduced now; doing so would be YAGNI before the second caller exists.

## Testing

### Functional (`tests/Functional/`)

- **Happy path** — request reset for an existing user → email captured via `symfony/mailer`'s test transport → extract token from email body → GET the reset URL → POST a new password → log in successfully with the new password.
- **Unknown email** — request reset for a non-existent address → same check-email response, no email sent.
- **Expired token** — fast-forward `expires_at` in the DB (or use a low-`lifetime` test config) → GET reset URL → redirected back to `/reset-password` with an error flash.
- **Reused token** — complete a reset, then GET the same link again → 404 / error flash.
- **Throttle** — second request within the window → still returns check-email, but no second email is in the test transport.

### Unit

Light. The bundle's internals are its own. We own the controllers and form types; both are exercised by the functional tests above. No dedicated unit tests unless a non-trivial branch surfaces during implementation.

## Out of scope (handled by other issues)

- **Admin-triggered reset and User CRUD** — issue #16.
- **Self-signup form** — issue #18.
- **Google SSO** — issue #19.
- **Broader rate limiting on public routes** — issue #23 (the bundle's per-email throttle only covers reset requests).
- **Branded HTML email template / i18n** — plain Twig text + minimal HTML for now; revisit when the rest of the email surface lands.
- **Force-logout of existing sessions on reset** — out of scope; revisit if a security incident motivates it.

## Acceptance criteria

- "Forgot your password?" link visible on `/login`.
- Submitting an email at `/reset-password` always returns the same neutral confirmation.
- Existing accounts receive a single-use, 1-hour reset link.
- Clicking the link allows the user to set a new password (min 12 chars, confirmed).
- Used or expired links are rejected with a non-PII error.
- All five functional scenarios above pass under `vendor/bin/phpunit`.
- `vendor/bin/grumphp run` passes (PHPStan level 10, PSR-12, schema validation, etc.).
