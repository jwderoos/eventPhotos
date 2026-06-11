# Single-use signup invitation links

**Issue:** [#31](https://github.com/jwderoos/eventPhotos/issues/31)
**Date:** 2026-06-11

## Background

The project has two existing onboarding paths: admin creates a user directly (#16) and (planned) public self-signup gated by a toggle (#18). Neither covers the "closed system, but I want to invite *this specific person*" case — admin generates a single-use link, shares it via their own channel, recipient creates an account at the other end. The recipient's email is unknown at invite time; self-signup remains off for everyone else.

This feature is independent of #18. Invites work whether public signup is on or off.

## Goals & non-goals

**In scope (v1)**
- Admin can create, view, and revoke invitation links.
- Each link is single-use, time-limited, and tied to a baked role.
- Recipient redeems via `/invite/{token}` — picks email + password + display name, gets logged in.
- Audit trail: who invited whom, when, did they redeem.

**Out of scope (deferred follow-ups)**
- Emailing the invite from the app. Admin shares the URL via their own channel.
- Bulk invite generation.
- Domain/email allow-lists.
- Rate limiting on redemption (the 128-bit selector + verifier defends against guessing; rate limits would address leaks, which they can't actually fix).
- Auto-cleanup of expired/used/revoked invites — they stay in the table as audit data.

## Architecture overview

Three units, each independently understandable and testable:

1. **`App\Entity\Invitation`** — persistence + state machine.
2. **`App\Service\Invitation\InvitationTokenService`** — token generation, parsing, and constant-time verification. Pure; no DB or HTTP dependencies; the easy place to write a security bug is concentrated here behind a focused unit-test suite.
3. **Controllers** — thin glue.
   - `App\Controller\Admin\InvitationController` — `ROLE_ADMIN`-gated `index` / `new` / `revoke`.
   - `App\Controller\Public\InvitationRedemptionController` — `GET` / `POST /invite/{token}`.

The symfonycasts/reset-password-bundle is **not** reused. Its tokens are bound to an existing `User` row via the bundle's trait, and invite tokens precede the user's existence. The crypto *pattern* (random selector + hashed verifier in DB) is mirrored in our own service. The bundle still backs the password-reset flow used elsewhere; that's unchanged.

## Token mechanism

Token URL shape: `/invite/{selector}.{verifier}`

- `selector` — 16 bytes random hex (32 chars). Public lookup key. Unique-indexed column.
- `verifier` — 32 bytes random hex (64 chars). The secret half. Only its `sha256` hex digest is stored (`hashed_verifier`).
- Verification: `hash_equals(stored_hash, hash('sha256', presented_verifier))` — constant-time, single-bool return so callers can't accidentally short-circuit on partial checks.
- Plaintext token is generated once, returned to the admin via flash bag on creation, and never persisted anywhere. After the flash is consumed (first index render after creation), the URL is unrecoverable. Matches the GitHub PAT / API-key reveal pattern.
- Route requirement: `requirements: ['token' => '[a-f0-9]+\.[a-f0-9]+']` — Symfony rejects malformed tokens at the router. The controller re-parses and re-validates as defence in depth.

## Data model

New table `invitations`. Generated via `bin/console doctrine:migrations:diff` — never hand-written (CLAUDE.md gate).

| column | type | notes |
|---|---|---|
| `id` | INT, PK, auto | |
| `selector` | VARCHAR(32), NOT NULL, UNIQUE | hex; the only lookup path |
| `hashed_verifier` | VARCHAR(64), NOT NULL | sha256 hex |
| `role` | VARCHAR(32), NOT NULL | `ROLE_ORGANIZER` / `ROLE_ADMIN`; validated by form, not enum-typed in DB (matches `User.roles` storage style) |
| `email` | VARCHAR(180), NULLABLE | filled at redemption; NOT unique |
| `created_by_id` | INT, NOT NULL, FK → `users.id` (ON DELETE RESTRICT) | refusing to delete admins with historical invites preserves audit |
| `created_at` | TIMESTAMPTZ, NOT NULL | |
| `expires_at` | TIMESTAMPTZ, NOT NULL | |
| `used_at` | TIMESTAMPTZ, NULLABLE | |
| `used_by_id` | INT, NULLABLE, FK → `users.id` (ON DELETE SET NULL) | SET NULL so the audit row survives a later user delete |
| `revoked_at` | TIMESTAMPTZ, NULLABLE | |
| `revoked_by_id` | INT, NULLABLE, FK → `users.id` (ON DELETE SET NULL) | |

**Status is derived, not stored.** `Invitation::status()` returns `InvitationStatus::Used` if `usedAt`, else `Revoked` if `revokedAt`, else `Expired` if `expiresAt < now()`, else `Pending`. Eliminates the class of bug where a persisted status column drifts from the flag fields.

**No `(email) WHERE used_at IS NULL` partial unique index.** Multiple outstanding invites to the same email are legitimate (admin sends a second, first one expires/gets revoked). Account hijack is prevented by the redemption-time user-collision check; locking it out at the schema level would add admin friction with no security gain.

**No compound indexes.** Only hot lookup is `selector` (covered by the UNIQUE constraint). Admin index page does `findBy([], ['createdAt' => 'DESC'])`; row count is tiny.

`InvitationRepository` methods:
- `findBySelector(string $selector): ?Invitation`
- `findAllOrderedByCreated(): list<Invitation>`

## Entity API

`Invitation` is a state machine like `Photo`. Mutations go through methods; illegal transitions throw `DomainException`.

- `__construct(string $selector, string $hashedVerifier, string $role, User $createdBy, DateTimeImmutable $expiresAt)` — constructs in Pending state. Email is null until redemption.
- `markUsed(User $newUser, string $email): void` — throws if not currently `Pending`.
- `revoke(User $admin): void` — throws if not currently `Pending`.
- `status(): InvitationStatus` — derived; precedence is Used > Revoked > Expired > Pending. (`markUsed` on a revoked invite throws, so Used-after-Revoked is unreachable; the precedence is defensive.)
- `isPending(): bool` — convenience wrapper.

`InvitationStatus` is a backed enum (`Pending`, `Used`, `Expired`, `Revoked`) used for templating/filtering, never persisted.

## Data flow

### Creation — admin `POST /admin/invites/new`
1. CSRF check; `denyAccessUnlessGranted('ROLE_ADMIN')`; bind `InvitationCreateType` (`role` choice + `expiresInDays` int 1–30, default 7).
2. `InvitationTokenService::generate()` returns `{plaintext, selector, hashedVerifier}`.
3. Persist `Invitation(selector, hashedVerifier, role, createdBy=$this->getUser(), expiresAt=now+days)`.
4. Stash the plaintext URL in a flash bag keyed `invitation.new_url`. **Plaintext token is never logged.**
5. Redirect to `/admin/invites`.
6. Index page reads + clears the flash; renders a one-time reveal banner above the table with a "Copy URL" button. Subsequent visits show no banner. The row itself only displays the selector (or a prefix) + status.

### Redemption — `GET /invite/{token}`
1. If `$this->getUser() !== null` → render an "already signed in as X — sign out first to redeem this invite" page (HTTP 200). No destructive auto-logout; invite stays Pending.
2. Parse the token (`InvitationTokenService::parse`). Malformed → generic invalid-or-expired page (HTTP 410).
3. Lookup by selector. Missing → same generic 410.
4. `verify(...)` constant-time + `isPending()` check. Fails → same generic 410.
5. Render signup form (`InvitationRedeemType`: email, displayName, password, repeatPassword). No fields pre-filled.

### Redemption — `POST /invite/{token}`
1. Repeat steps 1–4 from GET. Never trust GET-side state.
2. CSRF (`invite_redeem_{selector}`) + form validation (passwords match, displayName non-empty, email format, password ≥ 12 chars).
3. **Email collision check:** `UserRepository::findOneByEmail($email)`. Found → form error `"an account already exists for this email — sign in or reset your password"`. Invite stays `Pending`. No DB write.
4. Wrap in a single transaction:
   1. `EntityManager::lock($invitation, LockMode::PESSIMISTIC_WRITE)` to serialize concurrent redemptions.
   2. Re-check `$invitation->isPending()` after acquiring the lock. If not → roll back, render generic 410.
   3. Create `User($email, $displayName)`, add role from the invite, hash password, persist.
   4. `$invitation->markUsed($newUser, $email)`.
   5. Flush.
5. Programmatic login via `Security::login($newUser, 'form_login', 'main')`. Redirect to `/admin`.

### Revocation — `POST /admin/invites/{id}/revoke`
- CSRF (`invite_revoke_{id}`) + `ROLE_ADMIN`. If `!isPending()` → flash + redirect (no-op). Else `revoke($this->getUser())`, flush. Redirect to index.

## Security

- **Generic invalid-token page** for ALL invalid states (malformed, unknown selector, expired, revoked, used, verifier mismatch). Same body, same status (410 Gone), same response time within noise. Doesn't leak whether a token ever existed; doesn't distinguish "already used" (which would reveal token history to whoever finds the URL later).
- **Constant-time verifier compare** via `hash_equals`. Selector lookup is DB-equality on an indexed column; timing variance is dominated by template render.
- **CSRF** on every state-changing route:
  - `invite_create` for `/admin/invites/new`
  - `invite_revoke_{id}` for `/admin/invites/{id}/revoke`
  - `invite_redeem_{selector}` for `POST /invite/{token}`
- **Authorization**:
  - `/admin/invites/**` — `ROLE_ADMIN` enforced both in `access_control` AND `denyAccessUnlessGranted` in the controller (defence in depth). No new voter; admins see all invites (matches User CRUD).
  - Organizers cannot create invites in v1.
  - `/invite/**` — `PUBLIC_ACCESS` in `access_control`; controller rejects already-authenticated sessions.
- **No rate limiting in v1.** 128-bit selector is unguessable; rate-limiting addresses leaks, which it can't really fix. Deferred.
- **Logging** (never the plaintext token):
  - `INFO invite.created` — `{invite_id, role, created_by_id, expires_at}`
  - `INFO invite.redeemed` — `{invite_id, new_user_id, used_by_email}`
  - `INFO invite.revoked` — `{invite_id, revoked_by_id}`
  - `WARNING invite.redeem_failed` — `{reason: expired|revoked|used|unknown|malformed|verifier_mismatch, selector_prefix}` — useful for "someone is poking at invites" detection.

## Concurrency

Two parallel `POST /invite/{token}` against the same Pending invite must produce exactly one user. The pessimistic row lock + re-check inside the transaction (step 4 above) is the mechanism. Optimistic versioning would also work; pessimistic is simpler and contention is expected to be negligible.

## Routes summary

| route | method | auth | name |
|---|---|---|---|
| `/admin/invites` | GET | ROLE_ADMIN | `admin_invite_index` |
| `/admin/invites/new` | GET, POST | ROLE_ADMIN | `admin_invite_new` |
| `/admin/invites/{id}/revoke` | POST | ROLE_ADMIN | `admin_invite_revoke` |
| `/invite/{token}` | GET | PUBLIC | `public_invite_redeem` |
| `/invite/{token}` | POST | PUBLIC | `public_invite_redeem_submit` |

Token route requirement: `'token' => '[a-f0-9]+\.[a-f0-9]+'`.

Admin nav gets a new "Invites" link next to "Users".

## Forms

- `App\Form\InvitationCreateType` — `role` (ChoiceType, ROLE_ORGANIZER / ROLE_ADMIN), `expiresInDays` (IntegerType, default 7, min 1, max 30).
- `App\Form\InvitationRedeemType` — `email` (EmailType + email constraint), `displayName` (TextType, non-empty), `password` (RepeatedType, PasswordType, `Length(min: 12)` to match `ChangePasswordFormType` / `SetupFormType`).

## Templates

- `templates/admin/invitation/index.html.twig` — table + reveal banner (reads/clears flash `invitation.new_url`).
- `templates/admin/invitation/new.html.twig` — create form.
- `templates/public/invitation/redeem.html.twig` — signup form.
- `templates/public/invitation/invalid.html.twig` — generic "invalid or expired" page.
- `templates/public/invitation/already_signed_in.html.twig` — "you're signed in as X" page.

## Testing

### Unit (`tests/Unit/`)
- `InvitationTokenServiceTest`
  - `generate()` produces a parseable plaintext in the expected format.
  - `parse()` rejects malformed input (no dot, wrong charset, empty, just-a-dot, multiple dots).
  - `verify()` returns true on matching pair; false on tampered verifier; false on wrong-length input; smoke-checks constant-time behaviour with differential-input timings.
- `InvitationTest`
  - `status()` returns Pending → after `revoke()` returns Revoked.
  - `markUsed()` after `revoke()` throws.
  - `revoke()` after `markUsed()` throws.
  - `expiresAt` in the past with no terminal flag → Expired.
  - Constructor rejects empty selector / hashedVerifier / role.

### Integration (`tests/Integration/`, transactional via `dama/doctrine-test-bundle`)
- `InvitationRepositoryTest`
  - `findBySelector` returns the right row and null for unknown.
  - `findAllOrderedByCreated` returns rows in DESC `createdAt` order.

### Functional (`tests/Functional/`) — covers the issue's acceptance criteria
- `AdminInvitationFlowTest`
  - Admin POSTs create → redirected; index renders new row + one-time URL banner.
  - Second visit → banner gone (flash consumed); row still present.
  - Organizer hits `/admin/invites` → 403.
  - Admin POST revoke on Pending → status becomes Revoked.
  - Admin POST revoke on terminal (already Used/Revoked/Expired) → no state change; flash error.
- `InvitationRedemptionFlowTest`
  - Happy path: anonymous GET → form rendered; POST → user created with baked role; logged in; redirected to `/admin`.
  - Already logged in GET → "already signed in" page; invite unchanged.
  - Expired token → generic 410.
  - Revoked token → generic 410.
  - Used token (second attempt) → generic 410.
  - Unknown selector → generic 410.
  - Tampered verifier → generic 410 with no signal.
  - **Email collision** — existing user owns email; redemption form submits same → form error; invite remains Pending; no new user.
  - **Password mismatch** → form error; invite remains Pending.
- `InvitationConcurrencyTest`
  - Two parallel POSTs against the same Pending invite → exactly one succeeds; the other gets the generic 410. Verifies the pessimistic-lock path.

## Acceptance criteria mapping

- [x] Admin can create, view, revoke — `admin_invite_index/new/revoke` routes + `AdminInvitationFlowTest`.
- [x] `GET /invite/{token}` shows signup or friendly error — `public_invite_redeem` + `InvitationRedemptionFlowTest`.
- [x] `POST /invite/{token}` creates user with baked role + logs in — same flow test.
- [x] Redeemable exactly once — concurrency test + used-token branch.
- [x] Expired / revoked unredeemable — explicit branches.
- [x] Email collision: clear message, invite stays redeemable — collision test.
- [x] Functional tests cover happy path, four invalidation modes, collision — listed above.

## Files added

- `src/Entity/Invitation.php`
- `src/Entity/InvitationStatus.php`
- `src/Repository/InvitationRepository.php`
- `src/Service/Invitation/InvitationTokenService.php`
- `src/Service/Invitation/GeneratedToken.php` (small DTO from `generate()`)
- `src/Controller/Admin/InvitationController.php`
- `src/Controller/Public/InvitationRedemptionController.php`
- `src/Form/InvitationCreateType.php`
- `src/Form/InvitationRedeemType.php`
- `migrations/VersionXXXX_invitations.php` (generated)
- `templates/admin/invitation/{index,new}.html.twig`
- `templates/public/invitation/{redeem,invalid,already_signed_in}.html.twig`
- `tests/Unit/Service/Invitation/InvitationTokenServiceTest.php`
- `tests/Unit/Entity/InvitationTest.php`
- `tests/Integration/Repository/InvitationRepositoryTest.php`
- `tests/Functional/Admin/AdminInvitationFlowTest.php`
- `tests/Functional/Public/InvitationRedemptionFlowTest.php`
- `tests/Functional/Public/InvitationConcurrencyTest.php`

## Files modified

- `config/packages/security.yaml` — add `{ path: ^/admin/invites, roles: ROLE_ADMIN }` immediately after the existing `^/admin/users` rule so the ROLE_ADMIN gate applies before the `^/admin` ROLE_ORGANIZER rule matches. `/invite/{token}` is covered by the existing `^/` PUBLIC_ACCESS catch-all — no new rule needed.
- Admin layout template — add "Invites" nav link.
