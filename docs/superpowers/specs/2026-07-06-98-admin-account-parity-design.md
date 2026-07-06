# Admin parity with self-service account settings — design (#98)

## Goal

Give admins the ability to view and edit, for any user, **all the account settings a
user can edit themselves** on `/account`. Today admins can edit a user's display name +
role, send a password reset, and edit mail config. The gaps are **organizer styling +
brand** (colors/glow + brand label/logo/url) and **linked SSO identities** (view/unlink).

Everything lands on the existing admin user-detail page (`/admin/users/{id}/edit`),
reusing the self-service forms/logic scoped to a *target* user — mirroring the established
`UserMailConfigType` self+admin precedent.

## Self-service → admin parity map

| Setting | Self (`/account`) | Admin today | This spec |
|---|---|---|---|
| Display name | ✓ `AccountDisplayNameType` | ✓ `UserEditType` | unchanged |
| Role | — (not self-editable) | ✓ `UserEditType` (admin-only) | unchanged |
| Password | ✓ set/change | "Send reset email" only | unchanged — reset stays the admin equivalent |
| Organizer styling (colors/glow) | ✓ `OrganizerProfileType` | ✗ | **add** admin style form |
| Brand label/logo/url (#96) | ✓ `OrganizerProfileType` | ✗ | **add** (rides the same form) |
| Linked identities (view/unlink) | ✓ | ✗ | **add** admin view + unlink |
| Mail config | ✓ (`/admin/account/mail`) | ✓ `UserMailController` | unchanged |

## Key decisions

1. **Password: reset-email only.** Admins never set another user's password directly; the
   existing "Send password reset email" action is the admin equivalent. (Security: admins
   don't learn credentials.)
2. **Identities: view + unlink only, no "link".** Linking an SSO identity requires the
   *target* user to complete the OAuth flow (OAuth authenticates the actor, not an
   arbitrary user), so the admin side omits the "link Google" button that self-service has.
3. **Consolidated page.** New sections live on `/admin/users/{id}/edit`, mirroring
   `/account` (a single page of independent section-forms). Mail config stays its own admin
   sub-page — as it already is, and as it is for self-service too.
4. **Reuse, don't duplicate.** The admin style form is the same `OrganizerProfileType` the
   user uses. `loadOrCreateProfile` is extracted to a shared service so self and admin use
   one code path.

## Existing building blocks (already present, no change needed)

- `UserVoter::EDIT` — admin-only, gates the target user. `UserVoter::VIEW` for reads.
- `UserIdentityVoter::UNLINK` — **already grants `ROLE_ADMIN`**, so admins can unlink any
  identity with no voter change.
- `OrganizerProfileType` (post-#96) — colors/glow + brand label/logo/url in one form.
- The `#[Audited(...)]` attribute + `$this->audit` service used by `UserController::edit`.

## Components

### 1. Shared profile loader (small refactor)

Extract the one-liner `AccountController::loadOrCreateProfile()` into
`App\Service\Organizer\OrganizerProfileProvider`:

```php
final readonly class OrganizerProfileProvider
{
    public function __construct(private OrganizerProfileRepository $profiles) {}

    public function loadOrCreate(User $user): OrganizerProfile
    {
        return $this->profiles->findOneBy(['user' => $user]) ?? new OrganizerProfile($user);
    }
}
```

`AccountController` is refactored to inject and call it (replacing its private method) — no
behavior change; existing account tests must stay green. `UserController` injects the same
provider. Persist-if-new (`$em->persist($profile)` when `getId() === null`) stays in each
controller's write path, exactly as `AccountController::changeStyle` does today.

### 2. Admin styling + brand — `UserController`

- **`GET`/render:** `UserController::edit` additionally builds
  `$styleForm = createForm(OrganizerProfileType::class, $provider->loadOrCreate($target), ['action' => path('admin_user_change_style', {id})])` and passes it + `$target->getIdentities()`
  + a `brandLogoSet` bool to the template.
- **`POST /admin/users/{id}/style`** (`admin_user_change_style`):
  - `denyAccessUnlessGranted(UserVoter::EDIT, $target)`.
  - Bind `OrganizerProfileType` against the target's profile; on valid, persist-if-new +
    `$em->flush()`; audit (see §5); success flash; redirect to `admin_user_edit`.
  - On invalid, add an error flash and redirect to `admin_user_edit` (mirrors
    `AccountController::changeStyle`, which redirects to `account_show` on invalid rather
    than re-rendering — this keeps the style action from having to rebuild the name/role
    form).
- **`GET /admin/users/{id}/brand-logo`** (`admin_user_brand_logo`):
  - `denyAccessUnlessGranted(UserVoter::VIEW, $target)`.
  - Stream the target's brand-logo bytes from `brand_logos_storage`; 404 when no filename
    or `FilesystemException`. `Cache-Control: private, max-age=300` (named const).
  - Mirrors `AccountController::brandLogo` but scoped to `$target` (route param), not
    `getUser()`. This is the admin-side preview source (the self route is `getUser()`-bound
    and would leak/mismatch for a different user).

### 3. Admin identities — `UserController`

- **Render:** the identities table is built from `$target->getIdentities()` on the edit
  page (same columns as self-service: provider, email, linked-at). **No "link" button.**
- **`POST /admin/users/{id}/identities/{identityId}/unlink`**
  (`admin_user_identity_unlink`):
  - Resolve the `UserIdentity` by `{identityId}`; 404 if not found **or if it does not
    belong to `{id}`** (defence against cross-user id tampering).
  - `denyAccessUnlessGranted(UserIdentityVoter::UNLINK, $identity)` (admin already granted).
  - CSRF token `admin-unlink-identity-<identityId>`.
  - `$target->removeIdentity($identity); $em->remove($identity); $em->flush();` — mirrors
    `AccountController::unlinkIdentity`. Audit (see §5); flash; redirect to
    `admin_user_edit`.

### 4. Template — `templates/admin/user/form.html.twig`

Extend the edit-mode page into sections mirroring `/account` (only when `mode == 'edit'`):
1. Existing name/role form (posts to `admin_user_edit`) — unchanged.
2. **Styling & brand** section — a guarded current-logo `<img src="{{ path('admin_user_brand_logo', {id: target_id}) }}">` (shown when `brandLogoSet`) above `{{ form(styleForm) }}`. `styleForm` posts to `admin_user_change_style`.
3. **Linked identities** section — table of `identities`; each row a small POST form to
   `admin_user_identity_unlink` with the CSRF token. Empty-state text when none. No link
   button.
4. Existing "Send password reset email" button — unchanged.
5. Existing link to the mail sub-page — unchanged (add if not already linked from here).

The `new` mode page is unchanged (no target user yet).

### 5. Authorization & audit

- Every mutation: `denyAccessUnlessGranted(UserVoter::EDIT, $target)` (style) or
  `UserIdentityVoter::UNLINK` (identity). Reads: `UserVoter::VIEW`.
- Audit for parity with existing admin user actions: add `AuditAction` cases
  `UserStyleChange` and `UserIdentityUnlink`, and record them via the existing `#[Audited]`
  attribute / `$this->audit` service the way `edit()` records `UserEdit`/`UserRoleChange`
  (set `targetLabel($target->getEmail())`). If the audit subsystem makes per-action
  recording awkward for these routes, fall back to `LoggerInterface` info logs with
  `admin_user_id` + `target_user_id` — but prefer the audit trail.

## Testing

- **Unit** — `OrganizerProfileProviderTest`: returns the existing profile when present;
  returns a new (unpersisted) `OrganizerProfile` bound to the user when absent.
- **Functional (admin happy paths)** —
  - Admin edits a target user's style (e.g. a color) + a brand field via
    `admin_user_change_style`; reload from DB asserts persisted on the target's profile
    (and a *new* profile is created if the target had none).
  - `admin_user_brand_logo` serves the target's logo bytes; 404 when unset.
  - Admin unlinks a target's identity via `admin_user_identity_unlink`; identity row gone.
- **Functional (authorization)** —
  - A `ROLE_ORGANIZER` (non-admin) gets 403 on `admin_user_change_style`,
    `admin_user_brand_logo`, and `admin_user_identity_unlink` for another user.
  - `admin_user_identity_unlink` returns 404 when `{identityId}` belongs to a *different*
    user than `{id}` (no cross-user unlink).
- **Regression** — existing `AccountControllerTest` / `OrganizerProfileStyleTest` stay green
  after the `loadOrCreateProfile` → provider refactor.

## Non-goals

- Admin direct password-set (reset-email only).
- "Link identity as admin" (OAuth is actor-bound).
- A new "last login method" lockout guard — none exists for self-service today; unlinking a
  user's only login method remains possible. Flagged as a known limitation to revisit
  separately if desired; **out of scope** here to keep admin behavior at parity with self.
- Editing settings that are not self-editable (e.g. email is not editable on `/account`, so
  it stays out of scope).
