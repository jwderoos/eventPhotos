# Admin User CRUD + First-Run Bootstrap — Design

**Issue:** [#16](https://github.com/jwderoos/eventPhotos/issues/16)
**Date:** 2026-06-11
**Depends on:** #17 (password reset, merged) — reuses `ResetPasswordHelperInterface::generateResetToken()` per the seam declared in `2026-06-10-password-reset-design.md`.

## Goal

Two related features:

1. **Admin User CRUD** at `/admin/users` — list, create, edit, delete users; `ROLE_ADMIN`-gated.
2. **First-run bootstrap** — when zero users exist, the app funnels every request to `/setup`, a one-shot form that creates the first `ROLE_ADMIN` and disables itself afterwards.

These share no code, but ship together because the bootstrap is the on-ramp that makes user CRUD reachable on a fresh install.

## Background

The current state after #1–#15:

- `User` entity (`src/Entity/User.php`) — id, email (unique), displayName, password, `list<string> roles`. Has `addRole`/`removeRole` helpers; `getRoles()` auto-includes `ROLE_USER`.
- Role hierarchy `ROLE_ADMIN ⊃ ROLE_ORGANIZER ⊃ ROLE_USER` (security.yaml). `/admin/**` is gated to `ROLE_ORGANIZER`.
- `app:create-user` CLI command exists for seeding users from the shell.
- Password reset (#17) is wired through `ResetPasswordHelperInterface::generateResetToken(User $user)` and the `reset_password/email.html.twig` template — both reusable.
- Hand-built admin uses Symfony Forms + Twig + voters, DaisyUI (`dracula` theme) shell at `templates/admin/_base.html.twig`.
- `Event.owner` and `EventCollection.owner` are `nullable: false` with no `onDelete` cascade — the DB will refuse to delete a user that owns either.

## Scope

### In scope

**Bootstrap (one-shot, zero-user state):**

- `GET/POST /setup` — form fields: email, displayName, password, password (confirm).
- A `kernel.request` subscriber redirects every request (except `/setup` and dev assets) to `/setup` when `User` count is 0.
- On successful submit: persist user with `ROLE_ADMIN`, programmatically log them in, redirect to `/admin`.
- Once any user exists, `/setup` returns 404 and the subscriber stops redirecting.

**Admin User CRUD (`/admin/users`, `ROLE_ADMIN` only):**

- List view: id, email, displayName, role badge, action icons.
- Create: form fields email + displayName + role radio. On submit, persist with an unusable random password hash and immediately send a password-reset email (same template as self-service reset).
- Edit: displayName + role. Email is rendered read-only.
- Send-reset action on edit page: POST `/admin/users/{id}/send-reset` — fires another reset email if the user lost the first.
- Delete: POST `/admin/users/{id}/delete`. Refuses if the user owns any `Event` or `EventCollection` (flash error with counts). Refuses on self.
- Nav entry "Users" in the admin sidebar, visible only to `ROLE_ADMIN`.

### Out of scope (deliberately)

- **Email change.** Would invalidate pending reset tokens and add edge cases; defer to a future ticket.
- **Bulk import / CSV export.**
- **"Last admin" invariant beyond self-delete.** Self-delete and self-role-change are blocked; cross-admin role changes are allowed (if there are two admins, one can demote the other).
- **Audit log** of who modified which user.
- **Direct password setting on `/admin/users/new`.** Only `/setup` accepts a plaintext password (see Decision 2).

## Decisions

### 1. Bootstrap entry mechanism: dedicated `/setup` + universal redirect subscriber

A `kernel.request` event subscriber checks `userRepo.count([]) === 0` on every main request. If true and the path is neither `/setup` nor a dev asset (`/_*`), it sets a `RedirectResponse('/setup')`.

`SetupController` itself re-checks the user count and 404s when non-zero, defending against a request that arrives after the first admin is created.

**Rejected alternatives:**

- **Enumerated paths** (`/login`, `/admin/**`, `/reset-password`): more code, more chances to miss a path that ends up requiring auth later.
- **Inline bootstrap form on `/login`** (no new route): couples two unrelated forms in one controller and template; mixes the public login surface with a privileged one-shot setup.
- **CLI-only seeding** (`bin/console app:create-user` and nothing else): contradicts the issue text, and excludes anyone deploying via a hosting platform that doesn't expose a shell.

**Cost:** one COUNT query on every request. Acceptable — it's a single-row indexed scan and short-circuits once any user exists. If profiling ever flags it, cache the "has any user" flag.

### 2. Password handling: direct on `/setup`, auto-send reset on `/admin/users/new`

**`/setup`:** plaintext password fields (`RepeatedType` + `Length(min: 12)` to match `ChangePasswordFormType`).
**`/admin/users/new`:** no password field; auto-send a reset email after creating the user.

**Why split:** the only argument for a direct password field is "email may not be working yet on a fresh host, and an unreachable reset email would lock the admin out." That argument applies only to the first user — once any admin exists, they aren't locked out by a broken mailer (they can diagnose and fix it). For subsequent admin-created users, the tradeoffs flip:

- Plaintext lives on the admin's screen, in form-submit logs if anything's misconfigured, possibly in their password manager.
- A typo in the email field silently creates an orphan account; the reset-email flow fails closed (no delivery → no usable account).
- Two divergent password code paths means two surfaces to test and secure.

This deliberately diverges from the issue text ("No direct password editing — trigger a password reset email instead"). The issue's intent is preserved everywhere except the bootstrap path, where the lock-out failure mode justifies the exception.

### 3. Role UI: single radio (User / Organizer / Admin)

Because `ROLE_ADMIN` implies `ROLE_ORGANIZER` implies `ROLE_USER` via the role hierarchy, only the highest granted role is meaningful. The form stores a single role string in `User.roles[]` (omitting `ROLE_USER`, which `getRoles()` auto-adds). No checkbox UI — that would let admins create nonsense states like "ROLE_ORGANIZER without ROLE_USER" that look intentional but mean nothing.

### 4. Delete behavior: block when user owns content

Before removing a user, count owned `Event`s + `EventCollection`s. If non-zero, refuse with a flash:

> Cannot delete — user owns N event(s) and M collection(s). Reassign or delete them first.

**Rejected alternatives:**

- **Reassign to acting admin:** silent ownership change is surprising and means an admin can never cleanly "leave" the system without their content following them.
- **Cascade delete:** would wipe events, collections, photos, and photo files behind a single admin click. Too destructive.

### 5. Voter: self-protection

A new `UserVoter` with attributes `VIEW`, `EDIT`, `EDIT_ROLE`, `DELETE`. Self-modification is blocked for `EDIT_ROLE` and `DELETE` (an admin can still edit their own display name). Prevents the foot-gun where the last admin demotes or deletes themselves and locks the system out of administration.

Cross-admin demotion is *not* blocked at the voter level — if there are two admins, one can demote the other. Detecting "last admin" reliably is harder than it looks and out of scope here.

### 6. Service vs. inline

Create-with-reset-invite logic stays **inline in `Admin\UserController::new`** for now. A `UserService` is YAGNI until a second caller appears (likely #19 Google SSO password-add). When that ticket arrives, the controller logic gets extracted into a service.

## Architecture

### New files

```
src/Controller/Public/SetupController.php
src/Controller/Admin/UserController.php
src/Form/SetupFormType.php
src/Form/UserCreateType.php
src/Form/UserEditType.php
src/Security/Voter/UserVoter.php
src/EventSubscriber/FirstRunBootstrapSubscriber.php
templates/setup/start.html.twig
templates/admin/user/index.html.twig
templates/admin/user/form.html.twig
```

### Modified files

```
config/packages/security.yaml          # add /admin/users → ROLE_ADMIN access_control row
templates/admin/_base.html.twig        # add "Users" nav entry, ROLE_ADMIN-gated
src/Repository/UserRepository.php      # no changes (already supports findOneBy/count)
src/Repository/EventRepository.php     # add countByOwner(User): int
src/Repository/EventCollectionRepository.php  # add countByOwner(User): int
```

No migration. The `User` entity already has every column we need.

### Bootstrap subscriber

```php
final class FirstRunBootstrapSubscriber implements EventSubscriberInterface
{
    public function __construct(private UserRepository $users) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 32]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $path = $event->getRequest()->getPathInfo();
        if ($path === '/setup' || str_starts_with($path, '/_')) {
            return;
        }
        if ($this->users->count([]) > 0) {
            return;
        }
        $event->setResponse(new RedirectResponse('/setup'));
    }
}
```

Priority `32` is high enough to run before the firewall (which sits at `8`), so unauthenticated visits to `/admin` get redirected to `/setup` rather than `/login` when count=0.

### Create-user flow (auto-send reset)

`Admin\UserController::new` on valid submit:

```
$user = new User($email, $displayName);
$user->setPassword($passwordHasher->hashPassword($user, bin2hex(random_bytes(16))));
$user->addRole($mappedRole);
$em->persist($user);
$em->flush();                                  // need id before generateResetToken

$token = $resetPasswordHelper->generateResetToken($user);
$mailer->send(
    new TemplatedEmail()
        ->from(new Address('no-reply@eventfotos.local', 'eventFotos'))
        ->to($user->getEmail())
        ->subject('Set your eventFotos password')
        ->htmlTemplate('reset_password/email.html.twig')
        ->context(['user' => $user, 'resetToken' => $token])
);

$this->addFlash('success', sprintf('User created. Reset email sent to %s.', $email));
```

The "send another reset" button on the edit page is the same code minus the `new User(...)` and persist steps.

### Delete flow

```
$this->denyAccessUnlessGranted(UserVoter::DELETE, $target);          // 403 on self

$ownedEvents = $events->countByOwner($target);
$ownedCollections = $collections->countByOwner($target);
if ($ownedEvents + $ownedCollections > 0) {
    $this->addFlash('error', sprintf(
        'Cannot delete — %s owns %d event(s) and %d collection(s). Reassign or delete them first.',
        $target->getEmail(), $ownedEvents, $ownedCollections,
    ));
    return $this->redirectToRoute('admin_user_edit', ['id' => $target->getId()]);
}

$em->remove($target);
$em->flush();
$this->addFlash('success', 'User deleted.');
```

### Voter

```
attribute     | rule
------------- | -------------------------------------------------
VIEW          | isGranted(ROLE_ADMIN)
EDIT          | isGranted(ROLE_ADMIN)
EDIT_ROLE     | isGranted(ROLE_ADMIN) AND target.id !== currentUser.id
DELETE        | isGranted(ROLE_ADMIN) AND target.id !== currentUser.id
```

`EDIT_ROLE` is checked in `UserController::edit` *before* calling `setRoles()`. If denied, the form's submitted role is discarded silently and a flash explains why. (The form itself doesn't render the role field for self-edit — server-side check is the backstop.)

## Security

- `access_control` rule `{ path: ^/admin/users, roles: ROLE_ADMIN }` (inserted *before* the existing `^/admin` row) — firewall denies organizers at the URL layer.
- All state-changing POSTs (`new`, `edit`, `delete`, `send-reset`) use `_csrf_token` form fields validated via `isCsrfTokenValid()`.
- `/setup` does **not** require auth (by definition — there's no user to authenticate as). The subscriber's count-guard is what gates access; once a user exists, the route 404s.
- After successful `/setup`, the new admin is logged in via Symfony's `Security::login($user)`; subsequent requests use the session cookie.
- `UniqueEntity` constraint on `User.email` prevents duplicate email submissions from creating two rows; the existing `uniq_users_email` DB constraint is the backstop if a race slips past validation.

## Error handling

- `/setup` after a user exists: 404.
- `/admin/users/new` with duplicate email: form re-renders with the validator error.
- `/admin/users/{id}/delete` with owned content: flash error, redirect back to edit page; no DB write.
- `/admin/users/{id}/delete` on self: 403 from voter.
- `/admin/users/{id}/edit` role change on self: silently ignored, flash explains; display name still saves.
- Reset email send failure (mailer down): the user is already persisted at that point. Flash a warning ("User created but reset email failed to send — use 'Resend reset' on the edit page once mail is working") and continue. The admin can retry from the edit page.

## Testing

PHPUnit 13, `failOnDeprecation/Notice/Warning` all true; DAMA Doctrine Test Bundle for transactional rollback.

**Unit:**

- `tests/Unit/Security/Voter/UserVoterTest.php`
  - admin bypass for VIEW / EDIT
  - admin cannot EDIT_ROLE on self
  - admin cannot DELETE on self
  - organizer denied VIEW / EDIT
  - admin can EDIT_ROLE / DELETE on other users

**Integration:**

- `tests/Integration/Controller/Admin/UserControllerTest.php`
  - admin creates user → user row exists, role set correctly, reset email queued (assert mailer collected the message)
  - admin edits another user's display name + role → fields update
  - admin deletes user with no owned content → user removed
  - admin attempts delete of user with owned events → blocked, flash present, user still in DB
  - "Send another reset" → second message in mailer queue

**Functional:**

- `tests/Functional/Setup/FirstRunBootstrapTest.php`
  - empty DB: `GET /login`, `GET /admin`, `GET /reset-password` all redirect to `/setup`
  - empty DB: `POST /setup` with valid form creates user, logs in, redirects to `/admin`
  - after first admin: `GET /setup` returns 404
  - after first admin: `GET /login` renders the login form (no redirect)

- `tests/Functional/Admin/UserCrudTest.php`
  - organizer logged in: `GET /admin/users` → 403
  - admin logged in: `GET /admin/users` → 200, lists users
  - admin logged in: `POST /admin/users/{self.id}/delete` → 403, flash, redirect

## Open questions

None outstanding — all design decisions resolved in the brainstorming pass.

## References

- `docs/superpowers/specs/2026-06-10-password-reset-design.md` — declares the reuse seam this spec consumes (§"Reusability for #16").
- `docs/superpowers/plans/2026-06-09-event-photos-foundation.md` — context behind the hand-built admin pattern (Task 4).
- `src/Controller/Admin/EventCollectionController.php` — template for the controller shape this spec follows.
- `src/Controller/ResetPasswordController.php` — source of the reset-email composition copied into the create-user flow.
