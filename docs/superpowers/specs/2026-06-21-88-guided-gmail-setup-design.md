# Guided Gmail setup: prefilled SMTP preset on the mail-config form

Issue: #88
Date: 2026-06-21
Status: Approved (design)

## Problem

Organizers configure outgoing mail at `/admin/account/mail` by pasting a raw SMTP **DSN**
into a textarea (`UserMailConfigType` → `DsnValidator` → encrypt via `DsnVault` →
double-opt-in verify; per-organizer transport from #78). Gmail already works through this
path with an app password, e.g.
`smtps://name%40gmail.com:<app-pw>@smtp.gmail.com:465`. The problem is purely UX: the
organizer must know Gmail's host/port, generate an app password, and hand-write DSN syntax
— including URL-encoding the `@` in their address as `%40`, a silent footgun that surfaces
as a confusing "invalid DSN" error.

This makes Gmail the easy, guided path while keeping the custom-DSN option for everyone
else. **No OAuth** — `gmail.send` is a restricted scope gated by Google app verification +
an annual paid CASA assessment; deliberately ruled out.

## Decisions (confirmed)

1. **Port/encryption:** `smtps://…@smtp.gmail.com:465` (implicit TLS). Simplest mental
   model, no STARTTLS upgrade step.
2. **App-password whitespace:** strip **all** whitespace server-side before building the
   DSN. Google displays app passwords grouped as `xxxx xxxx xxxx xxxx`; users paste spaces.
3. **Provider discriminator:** persist which provider was used (entity column + migration)
   so the form re-opens in the correct mode. We still never decrypt the stored DSN back to
   the form, so the Gmail email/app-password inputs remain empty on re-open — the
   discriminator only drives which input layout opens and which provider radio is selected.
4. **`fromAddr` in Gmail mode:** defaults to the entered Gmail address, but stays visible
   and editable (a verified send-as alias may differ from the Gmail login). Default only
   applies when the field is left blank.

## Scope (v1)

- Provider selector on the mail-config form: `Custom (SMTP DSN)` (current behaviour) and
  `Gmail`.
- Gmail mode replaces the DSN textarea with `Gmail address` (email) + `App password`
  (masked) inputs, plus help text linking `https://myaccount.google.com/apppasswords` and a
  one-line note that 2-Step Verification must be enabled.
- On submit in Gmail mode the server assembles the DSN and feeds it through the **existing**
  `DsnValidator` → `DsnVault` → verification pipeline unchanged.
- `From address` defaults to the Gmail address in Gmail mode (editable).
- Custom mode is byte-for-byte the current flow — no regression.
- Fix stale "falls back to the platform default" copy (stale since #77 removed the platform
  fallback) everywhere it appears on this screen — not only the two template lines named in
  the issue.

## Out of scope (deferrable)

- OAuth / "Connect Gmail" (ruled out — restricted scope + paid verification).
- Other named presets (Outlook/Office365). The selector is built to extend; only Gmail
  ships in v1.
- Validating the credential is specifically an *app password* vs. account password —
  Google's SMTP rejects the account password at verification time, surfacing as a failed
  verification (good enough).

## Design

### 1. Enum + entity field

- **`App\Enum\MailProvider`** — backed string enum: `Custom = 'custom'`, `Gmail = 'gmail'`.
- **`UserMailConfig`** gains a non-nullable `provider` column (string, length 16, default
  `'custom'`). The constructor and `applyConfig()` accept a `MailProvider`.
  - Provider is UI metadata only. It does **not** factor into the `applyConfig()`
    re-verification decision — a DSN or `fromAddr` change already triggers re-verify, and a
    provider switch always implies a DSN change anyway.
- **Migration** generated via `bin/console doctrine:migrations:diff` (never hand-written,
  per CLAUDE.md), backfilling existing rows to `'custom'`. `getDescription()` text may be
  edited; DDL is not.

### 2. Shared DSN assembly — `App\Service\Mail\GmailDsnFactory`

Stateless service:

```php
public function build(string $email, string $appPassword): string
```

- Strips all whitespace from `$appPassword` (`preg_replace('/\s+/', '', …)`).
- Returns `sprintf('smtps://%s:%s@%s:%d', rawurlencode($email), rawurlencode($pw), self::HOST, self::PORT)`
  with `HOST = 'smtp.gmail.com'` and `PORT = 465` as class constants (keeps `phpmnd` green).

Both `AccountMailController::update` and `UserMailController::update` call this from a single
Gmail branch. Extracting it (rather than inlining the branch in both controllers) keeps the
`phpcpd` 50-line / 100-token duplication gate green — the two `update()` methods are already
near-identical.

### 3. Form — `UserMailConfigType`

- Add unmapped `provider` `ChoiceType` (`custom` / `gmail`, default `custom`) and unmapped
  `gmailEmail` (email) / `gmailAppPassword` (masked text) fields. Keep existing `dsn`,
  `fromAddr`, `fromName`.
- **Conditional validation via a `FormEvents::POST_SUBMIT` listener** reading the submitted
  `provider`:
  - `custom` → `dsn` required (`NotBlank` + `Length(max: 1024)`) and `fromAddr` required
    (`NotBlank` + `Email`) — identical to today.
  - `gmail` → `gmailEmail` required (`NotBlank` + `Email`) and `gmailAppPassword` required
    (`NotBlank`); `fromAddr` optional (defaulted in the controller when blank).
  - The currently-unconditional `NotBlank` on `dsn` / `fromAddr` moves into this listener so
    custom mode is unchanged while Gmail mode doesn't demand a DSN. `Email`/`Length`
    constraints that are harmless when the field is blank may stay as field-level.

### 4. Controllers (both `AccountMailController` and `UserMailController`)

After `isValid()`, read `provider`:

- **gmail:** `$email = gmailEmail`; `$dsn = $gmailDsnFactory->build($email, $appPassword)`;
  `$fromAddr = ($fromAddr !== '') ? $fromAddr : $email` (server-side default for the no-JS
  path). Resolved `MailProvider::Gmail`.
- **custom:** current `$dsn = dsn field` path. Resolved `MailProvider::Custom`.

The resolved `MailProvider` is passed into `new UserMailConfig(...)` / `applyConfig(...)`.
Everything downstream — `DsnValidator::validate`, `DsnVault::encrypt`, persist, audit,
`sendVerification` — is untouched.

### 5. Template + Stimulus

- `templates/admin/account/mail/edit.html.twig`: provider selector at the top, then two
  server-rendered blocks (DSN textarea / Gmail inputs). The stored `config.provider` (when a
  config exists) selects the default radio and visible block on GET.
- New `assets/controllers/mail_provider_controller.js` (Stimulus, matching existing
  controllers): toggles which block is visible based on the provider radio/select, and
  pre-fills the `fromAddr` input from `gmailEmail` (only when `fromAddr` is empty, so an
  explicitly-typed alias is never clobbered).
- Gmail block: help text + link to `https://myaccount.google.com/apppasswords` and the
  one-line 2-Step-Verification note.
- **No-JS fallback:** both blocks render; the provider select drives server-side conditional
  validation.

### 6. Stale-copy cleanup

Replace "falls back to / goes out from the platform default" wording with **"Without a
verified configuration, event mail cannot be sent."** (and equivalent) in:

- `edit.html.twig` line ~21 (intro paragraph).
- `edit.html.twig` line ~55 (`data-turbo-confirm` on the Clear form).
- `AccountMailController::clear` success flash (~line 276): "Event emails will be sent from
  the platform default." — reword to reflect that no mail can be sent without a verified
  config.

(Grep `platform default` across `src/Controller/Admin/*MailController.php` and
`templates/admin/account/mail/` to catch any other occurrence before finishing.)

## Tests

### Unit

- **`GmailDsnFactory`**: `name@gmail.com` + an app password containing URL-significant
  characters and embedded spaces → produces a DSN that `Dsn::fromString` parses back to host
  `smtp.gmail.com`, port `465`, scheme `smtps`, the exact user, and the de-spaced password.
- **`UserMailConfigType`**: gmail mode requires `gmailEmail` + `gmailAppPassword` and rejects
  when blank; custom mode requires `dsn`; gmail mode does not require `dsn`.

### Functional

- Submit the form in Gmail mode → a verification email is attempted through a Gmail-shaped
  DSN (assert via existing `InMemoryTransportFactory`); the assembled DSN passes
  `DsnValidator`; `fromAddr` defaults to the Gmail address when left blank; the persisted
  `UserMailConfig.provider` is `gmail`.
- Custom-DSN mode still works exactly as before (regression guard); persisted provider is
  `custom`.
- Apply the Gmail-mode functional assertions to the admin-on-behalf path
  (`UserMailController`) as well, at least at smoke level.

## Files touched

- `src/Enum/MailProvider.php` (new)
- `src/Service/Mail/GmailDsnFactory.php` (new)
- `src/Entity/UserMailConfig.php` (provider column + ctor/applyConfig signature)
- `migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- `src/Form/UserMailConfigType.php` (provider + gmail fields + conditional validation)
- `src/Controller/Admin/AccountMailController.php` (gmail branch + stale copy)
- `src/Controller/Admin/UserMailController.php` (gmail branch)
- `templates/admin/account/mail/edit.html.twig` (selector, blocks, copy)
- `assets/controllers/mail_provider_controller.js` (new)
- `tests/Unit/Service/Mail/GmailDsnFactoryTest.php` (new)
- `tests/Unit/Form/UserMailConfigTypeTest.php` (new or extended)
- `tests/Functional/...` mail-config tests (extended)
