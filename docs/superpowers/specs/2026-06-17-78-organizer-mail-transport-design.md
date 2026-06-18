# #78 — Per-organizer mail transport configuration

**Status:** Design approved 2026-06-17
**Issue:** [#78](https://github.com/jwderoos/eventPhotos/issues/78)
**Unblocks:** #77 (visitor "photos are live" notification), and future event-scoped mailings (digests, etc.)

## Goal

Every organizer should be able to configure their own SMTP transport so that event-scoped emails go out from their domain — not the platform's. The platform `MAILER_DSN` stays the fallback for organizers who haven't configured one and for all non-event-scoped flows (invitations, password resets).

This ticket builds the subsystem. #77 is the first consumer.

## Non-goals (deferred)

- Per-event override (one organizer sending different events from different domains). YAGNI today.
- Per-provider GUI configurator (form fields per provider). v1 is a single "paste your DSN" textarea with a help link.
- Automatic SPF/DKIM/DMARC verification by querying DNS. Documented in help text; not enforced.
- Bounce / complaint webhook handling.
- OAuth-authenticated Google Workspace / Microsoft 365 transports. SMTP + app passwords only for now.
- API-based transports (`sendgrid+api`, `ses+api`, etc.). v1 supports `smtp` + `smtps` only — extra providers add bridge dependencies and test surface without a current consumer asking for them.
- Migrating existing platform-level mailers (`UserController::sendInviteEmail`, `ResetPasswordController::sendPasswordResetEmail`) to the resolver. They have no event context and stay on platform default.
- Encryption key rotation. The procedure (decrypt-all + re-encrypt-with-new-key console command) is documented but not built.
- Audit-table unification with #75. The local `user_mail_config_audits` table is designed to be subsumed when #75 lands.

## Locked decisions

| | |
|---|---|
| Encryption key storage | `MAIL_CONFIG_ENCRYPTION_KEY` env var (32 raw bytes, base64-encoded) on the php-fpm container. Same shape as existing `DATABASE_URL`, `MAILER_DSN` secrets. |
| Transport schemes | `smtp`, `smtps` only. |
| SMTP host allow-list | Hard reject at save time — DSN never persists if the host resolves to RFC1918, loopback, link-local, multicast, or reserved space. |
| Send-time failures | Resolver throws `TransportException` up. Messenger retries (3×) and dead-letters. No silent fallback to platform; no auto-unverify. |
| Verification mail error UX | Categorized + raw — e.g. "Authentication failed: 535 5.7.8 Username and Password not accepted". |
| Consumer scope | Event-scoped only. Resolver's primary surface is `forEvent(Event)`. |
| Data shape | Separate `UserMailConfig` entity, one-to-one to `User`. Absence of row = no config. |
| Re-verification trigger | Editing the DSN or `from_addr` clears `verified_at` and auto-sends a fresh verification mail. `from_name`-only changes do not reverify. |
| DNS re-resolution at send time | Not done. Loopback is not a usable mail relay, so post-verification DNS swap to RFC1918 isn't a realistic exfiltration vector. |

## Architecture

### Domain model

Three new entities. Owning-side conventions follow the rest of the codebase.

**`App\Entity\UserMailConfig`** — table `user_mail_configs`.

| column | type | notes |
|---|---|---|
| `id` | `BIGSERIAL PRIMARY KEY` | |
| `user_id` | `INT NOT NULL UNIQUE` | FK → `users.id`, `ON DELETE CASCADE` |
| `dsn_ciphertext` | `BYTEA NOT NULL` | libsodium `crypto_secretbox` output |
| `dsn_nonce` | `BYTEA NOT NULL` | 24-byte nonce, fresh per encrypt |
| `from_addr` | `VARCHAR(254) NOT NULL` | RFC 5321 max length |
| `from_name` | `VARCHAR(120) NULL` | display name |
| `verified_at` | `TIMESTAMPTZ NULL` | set on successful click-through |
| `verification_token` | `VARCHAR(64) NULL` | URL-safe base64 (32 raw bytes); cleared on verify |
| `verification_sent_at` | `TIMESTAMPTZ NULL` | for 24h expiry check |
| `created_at`, `updated_at` | `TIMESTAMPTZ NOT NULL` | |

Domain methods (state-machine discipline, mirroring `Photo`):
- `markVerified(): void` — throws if already verified or no pending token. Sets `verified_at`, clears `verification_token`.
- `regenerateVerificationToken(): void` — rotates `verification_token`, bumps `verification_sent_at`, clears `verified_at`.
- `applyConfig(EncryptedDsn $envelope, string $fromAddr, ?string $fromName): bool` — updates fields, returns `true` if a re-verification is required (DSN or `from_addr` changed), `false` if only `from_name` differs.

Index: implicit unique on `user_id` covers all lookups.

**`App\Entity\UserMailConfigAudit`** — table `user_mail_config_audits`. Designed to be folded into #75's generic audit table later.

| column | type | notes |
|---|---|---|
| `id` | `BIGSERIAL PRIMARY KEY` | |
| `user_id` | `INT NOT NULL` | subject; FK → `users.id` `ON DELETE CASCADE` |
| `actor_user_id` | `INT NULL` | who changed it; FK → `users.id` `ON DELETE SET NULL` |
| `actor_email_snapshot` | `VARCHAR(254) NOT NULL` | survives actor deletion |
| `action` | enum `set` \| `verified` \| `cleared` \| `verification_resent` | PHP enum (`App\Enum\MailConfigAuditAction`), mapped via Doctrine `string` |
| `from_addr_snapshot` | `VARCHAR(254) NOT NULL` | the `from_addr` at time of action; snapshotted before `cleared` deletes the row |
| `created_at` | `TIMESTAMPTZ NOT NULL` | |

Audit rows survive `UserMailConfig` deletion (the FK is to `User`, not `UserMailConfig`). They are deleted only when the subject user is deleted.

**`App\Entity\User`** — single addition.

```php
#[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
private ?UserMailConfig $mailConfig = null;
```

Inverse side; owning side on `UserMailConfig`. Lazy-loaded — most User reads don't touch mail config.

### Services

Three new services. Each has one job and one source of state.

**`App\Service\Mail\DsnVault`** — encryption-at-rest only.

```php
final readonly class DsnVault
{
    public function __construct(
        #[\SensitiveParameter]
        #[Autowire('%env(base64:MAIL_CONFIG_ENCRYPTION_KEY)%')]
        private string $key,
    ) {}

    public function encrypt(#[\SensitiveParameter] string $dsn): EncryptedDsn;
    public function decrypt(EncryptedDsn $envelope): string;
}
```

- Pure libsodium `sodium_crypto_secretbox` (authenticated symmetric encryption, in PHP core since 7.2).
- `EncryptedDsn` is a `readonly` value object: `{ciphertext: string, nonce: string}`.
- `#[\SensitiveParameter]` keeps the key and plaintext DSN out of stack traces.
- `sodium_crypto_secretbox_open()` returns `false` on MAC failure (wrong key or tampered ciphertext). The vault wrapper converts that into a thrown `\SodiumException` so callers can rely on exceptions rather than truthy checks.

**`App\Service\Mail\DsnValidator`** — pre-persist safety gate.

```php
final readonly class DsnValidator
{
    public function __construct(private DnsResolver $dns) {}
    public function validate(#[\SensitiveParameter] string $dsn): void;  // throws DsnRejected
}
```

- Parses with `Symfony\Component\Mailer\Transport\Dsn::fromString()`.
- Scheme must be `smtp` or `smtps` — anything else (`gmail+smtp`, `null`, `http`, …) → `DsnRejected::scheme($scheme)`.
- Host resolved via `DnsResolver` (a thin interface over `dns_get_record($host, DNS_A | DNS_AAAA)` so tests can stub it). Every A + AAAA result is inspected; any private/loopback/link-local/multicast/reserved IP in the set → `DsnRejected::host($host, $rejectedIp)`. NXDOMAIN / empty result → `DsnRejected::unresolvable($host)`.
- Called once at save time. Not called at send time (per locked decision).

`App\Service\Mail\DnsResolver` is a one-method interface (`resolve(string $host): array`) with `App\Service\Mail\SystemDnsResolver` as the prod impl and `App\Tests\Fake\FakeDnsResolver` for tests.

**`App\Service\Mail\OrganizerMailerResolver`** — the consumer-facing surface.

```php
final readonly class OrganizerMailerResolver
{
    public function __construct(
        private DsnVault $vault,
        private MailerInterface $platformMailer,  // autowired platform default
    ) {}

    public function forEvent(Event $event): MailerInterface;
    public function forUser(User $user): MailerInterface;
    public function isCustomActive(User $user): bool;
}
```

- `forEvent` is sugar over `forUser($event->getOwner())`.
- Resolution: user has a `UserMailConfig` with `verified_at !== null` → decrypt DSN via vault, build `Symfony\Component\Mailer\Mailer` from it. Else return `$this->platformMailer`.
- Mailers built per call. No cache. The async-Messenger handler reality is "one transport call per message" — caching is wasted complexity.
- Resolver never catches `TransportException`. It propagates.
- Resolver catches `\SodiumException` from `DsnVault::decrypt` (raised if the ciphertext can't be opened — wrong key, tampered row, or corrupted nonce). On that branch it logs at error level and returns the platform mailer. The org-visible signal is that mail is suddenly going out from the platform address again; the operator-visible signal is the log line. Treat this as "broken row, fall back" rather than "throw and dead-letter" because the row may still be salvageable by clearing + reconfiguring, and dead-lettering every queued message during a key rotation outage is worse than degrading gracefully.

**`App\Service\Mail\TransportBuilder`** — bypass for the verification flow.

The verification flow needs a mailer built from a candidate DSN that hasn't been persisted yet (because verification hasn't succeeded). The controller calls `TransportBuilder::fromDsn(string $dsn): MailerInterface` directly, bypassing the resolver. Same `Symfony\Component\Mailer\Transport::fromDsn(...)` plumbing underneath.

### Routes & flows

| route | controller | gate |
|---|---|---|
| `GET  /admin/account/mail` | `Admin\AccountMailController::edit` | logged-in |
| `POST /admin/account/mail` | `…::update` | logged-in + CSRF |
| `POST /admin/account/mail/resend` | `…::resendVerification` | logged-in + CSRF |
| `GET  /admin/account/mail/verify/{token}` | `…::verify` | logged-in + token + 24h |
| `POST /admin/account/mail/clear` | `…::clear` | logged-in + CSRF |
| `GET  /admin/users/{id}/mail` | `Admin\UserMailController::edit` | `ROLE_ADMIN` |
| `POST /admin/users/{id}/mail` | `…::update` | `ROLE_ADMIN` + CSRF |
| `POST /admin/users/{id}/mail/resend` | `…::resendVerification` | `ROLE_ADMIN` + CSRF |
| `POST /admin/users/{id}/mail/clear` | `…::clear` | `ROLE_ADMIN` + CSRF |

All routes are `/admin/**` and already gated by `ROLE_ORGANIZER` via `security.yaml`. The admin sub-tree adds `ROLE_ADMIN`.

#### Save flow (`POST /admin/account/mail`)

1. CSRF check.
2. Form (`UserMailConfigType`) binds `dsn`, `from_addr`, `from_name`.
3. `DsnValidator::validate($dsn)`. Throws → caught, categorized via `DsnRejected` reason code, re-rendered form with field-level error. Nothing persisted.
4. Compare submitted `dsn` + `from_addr` against current entity:
   - If both unchanged AND only `from_name` differs → save without touching verification state. Audit row `action: set` written. No verification mail sent.
   - Otherwise → encrypt new DSN via `DsnVault`. Persist updated `UserMailConfig` (or create one). `regenerateVerificationToken()` clears `verified_at`, rotates token, sets `verification_sent_at = now()`. Audit row `action: set`.
5. `EntityManager::flush()` — **DB write commits before email send.**
6. Build candidate transport via `TransportBuilder::fromDsn($dsn)`.
7. Send verification email (`TemplatedEmail`, `email/mail-config/verify.html.twig` + `.txt.twig`) **through the candidate transport**, to `from_addr`. Subject: "Verify your eventFotos mail configuration."
8. On `TransportException`: row stays persisted as unverified. Flash carries categorized error (e.g. "Authentication failed: 535 5.7.8 ..."). User can hit "resend" to retry the email without re-entering the DSN.
9. On success: flash "Verification email sent to {from_addr}. Click the link within 24 hours."

**DB-first ordering rationale:** if the email fails after the DB commit, the user keeps their DSN entry and can retry via "resend." The reverse ordering (email first, then DB) loses the entry on email failure, which is worse UX. The state "config persisted but unverified" is consistent — the resolver still returns the platform mailer.

#### Verify flow (`GET /admin/account/mail/verify/{token}`)

1. Look up `UserMailConfig` `WHERE verification_token = ?`. The token is 32 bytes of entropy with a 24h lifetime — no separate hash-the-token-before-storing dance is warranted at this risk level (the DB equality check is the comparison; PHP-level timing isn't reachable for an attacker who doesn't already have the token).
2. Check `verification_sent_at + 24h >= now()`. Expired → flash "Verification link expired. Click resend to generate a new one." Redirect to edit page.
3. Check `mailConfig.user === currentUser`. If not, 403 — protects against a logged-in user clicking another user's verification link (the verification mail is delivered to the target user, but defence-in-depth on the route).
4. `markVerified()`. Audit row `action: verified` with `actor_user_id = currentUser` (always the subject themselves; admins editing other users still cannot click on their behalf).
5. Flash "Mail configuration verified." Redirect to edit.

#### Resend flow (`POST /admin/account/mail/resend`)

1. CSRF check.
2. Load current `UserMailConfig`. 404 if absent.
3. Decrypt DSN. `regenerateVerificationToken()`. Flush. Audit row `action: verification_resent`.
4. Send verification email through the candidate transport (same as save flow, step 7). Failure UX identical.

#### Clear flow (`POST /admin/account/mail/clear`)

1. CSRF check.
2. Snapshot `from_addr` for audit.
3. Delete `UserMailConfig` row. Flush.
4. Audit row `action: cleared` with `from_addr_snapshot` populated.
5. Flash "Mail configuration cleared. Event emails will now be sent from the platform address."

#### Admin-edits-other-user routes

Identical handlers, except:
- The route resolves `target = User` from `{id}`.
- Audit `actor_user_id` is the admin, not the target.
- The verification email is delivered to the target's configured `from_addr`. The target (not the admin) must log in and click the link. The admin cannot verify on the target's behalf — verification requires controlling the destination mailbox.

### Configuration

New env var:

```dotenv
# .env (placeholder, do NOT commit a real key)
MAIL_CONFIG_ENCRYPTION_KEY=

# .env.local / TrueNAS env (real key, 32 random bytes base64-encoded)
# generate with: php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
```

`config/services.yaml` binds the resolver and validator to autowire. Test overrides under `when@test:` (see Tests section).

The `MAIL_CONFIG_ENCRYPTION_KEY` is documented in the deploy runbook with these notes:
- Generate once per environment (dev, test, prod). Never share between environments.
- Back up to a password manager. Losing it bricks every saved organizer config.
- Rotation procedure exists in concept (decrypt-all → re-encrypt-with-new-key) but is not built in v1.

### Twig + UI

Two new templates:
- `templates/admin/account/mail/edit.html.twig` — form (DSN textarea, `from_addr`, `from_name`), status badges (Verified ✓ / Pending verification / Not configured), action buttons (Save, Send verification, Clear).
- `templates/email/mail-config/verify.html.twig` + `.txt.twig` — verification email. Plain content, single CTA link, `referrer-policy: no-referrer` meta. No third-party content.

Status badges are read off `OrganizerMailerResolver::isCustomActive($user)` + the `UserMailConfig` itself for the "Pending" intermediate state.

## Tests

### Unit (`tests/Unit/Service/Mail/`)

- **`DsnValidatorTest`** — parametric, no kernel. One case per:
  - Rejected scheme: `http`, `null`, `gmail+smtp`, `sendgrid+api`, `mailto`, empty.
  - Rejected host: `127.0.0.1`, `10.0.0.1`, `192.168.1.1`, `172.16.0.1`, `169.254.1.1`, `::1`, `fc00::1`, `224.0.0.1` (multicast), `0.0.0.0`.
  - Valid: `smtp.example.com` resolving to `93.184.216.34`.
  - Mixed: host resolves to one public + one private IP → rejected (defence in depth).
  - NXDOMAIN: unresolvable host → rejected.
- **`DsnVaultTest`** — encrypt → decrypt → equals; decrypt with wrong key throws; decrypt with tampered ciphertext throws; two consecutive encrypts produce different ciphertext (fresh nonce).
- **`UserMailConfigTest`** — `markVerified()` clears token + sets timestamp; calling twice throws `DomainException`; `regenerateVerificationToken()` rotates token and bumps timestamp; `applyConfig()` returns `true` when DSN changes, `false` when only `from_name` changes.
- **Token generator** — 32-byte URL-safe base64, no `+` `/` `=` chars, length matches expected (43 chars).

### Integration (`tests/Integration/`)

Kernel + real DB via `dama/doctrine-test-bundle`.

- **`OrganizerMailerResolverTest`**
  - User without `UserMailConfig` → `forEvent` returns the platform mailer (assert by identity).
  - User with config but `verified_at = null` → platform mailer.
  - User with config + `verified_at` set → custom mailer (assert by transport DSN host).
- **`UserMailConfigRepositoryTest`**
  - Cascade-delete `User` removes `UserMailConfig` row.
  - Clearing `UserMailConfig` does not remove `UserMailConfigAudit` rows.
  - Audit rows for a deleted user are deleted via `ON DELETE CASCADE`.
- **Encryption round-trip across persist/fetch** — write a config, evict EM, re-fetch, decrypt via vault, equals original DSN.
- **Verification expiry** — row with `verification_sent_at = now() - 25h` and matching token: verify route rejects with expired error.

### Functional (`tests/Functional/Admin/`)

Full HTTP stack with the in-memory transport infrastructure (below).

- **`AccountMailFlowTest::testFullVerifyAndSendCycle`** — log in as organizer; POST valid DSN; assert verification email captured by the in-memory transport for the configured host; extract token from message body; GET verify link; assert config now verified; trigger a `forEvent` send through the resolver; assert it routes through the in-memory transport for the organizer's host **and not** through the global `null://null` collector.
- **`AccountMailFlowTest::testDsnValidatorRejectsPrivateHost`** — POST `smtp://localhost:25` → 422-ish form re-render, field error present, no row persisted, no email sent.
- **`AccountMailFlowTest::testCsrfRejection`** — POST without a valid CSRF token: the form re-renders with a CSRF-violation error (or, on raw `isCsrfTokenValid` routes like clear/resend, the controller returns an error response). Assert no state change in either case.
- **`AccountMailFlowTest::testVerificationEmailFailureKeepsConfigUnverified`** — set the in-memory factory to throw for one host; POST that DSN; assert row persisted with `verified_at = null`, flash carries categorized error, "Resend verification" button visible.
- **`AccountMailFlowTest::testResendRotatesTokenAndResends`** — save → grab token A → resend → grab token B → assert tokens differ → old token now 404s.
- **`AccountMailFlowTest::testVerifyTokenExpired`** — manually age `verification_sent_at` past 24h → verify link rejected.
- **`AccountMailFlowTest::testVerifyTokenOneShot`** — first click verifies, second click 404s.
- **`AccountMailFlowTest::testCrossUserVerifyForbidden`** — log in as user A, click user B's verify link → 403, no state change on either user.
- **`AdminUserMailFlowTest::testAdminEditOtherUser`** — admin POSTs DSN for target; verification email captured; target user logs in and clicks → verified; audit row records admin as `actor_user_id`, target as `user_id`.

### Test infrastructure: per-host in-memory transport

Symfony's `MailerAssertions` can only see messages routed via the autowired `MailerInterface`. It cannot distinguish platform-default sends from per-call mailers built by `OrganizerMailerResolver` or `TransportBuilder`. This is the load-bearing piece for proving the resolver does what it claims.

Approach — register `App\Tests\Mail\InMemoryTransportFactory` implementing `Symfony\Component\Mailer\Transport\TransportFactoryInterface`, only under `when@test:` via `config/services_test.yaml` (same pattern as `FakeGoogleOAuthClient`). It accepts all `smtp://*` + `smtps://*` schemes and returns `App\Tests\Mail\InMemoryTransport` instances **keyed by host**. The bundle exposes:

```php
final class CapturedMail
{
    /** @return list<RawMessage> */
    public static function messagesForHost(string $host): array;
    public static function reset(): void;  // explicit setUp() call in each functional test
    public static function throwOnHost(string $host, \Throwable $e): void;  // for failure tests
}
```

Tests assert:

```php
$this->client->request('POST', '/admin/account/mail', $payload);
self::assertCount(1, CapturedMail::messagesForHost('smtp.example-organizer.test'));
self::assertEmailCount(0);  // global platform null://null collector — NOT hit
```

`MAILER_DSN=null://null` (the existing test default) catches anything that erroneously routes through the platform — that's the "no leakage" assertion. Custom transport hits land in `CapturedMail`.

## Risks

- **SSRF via SMTP DSN** — the primary attack surface. Mitigation: `DsnValidator` scheme + host allow-list. Pen-test before shipping. Specifically: confirm that a malicious organizer cannot supply a DSN that probes the internal network at save time (the validator's DNS resolution itself is a probe — `dns_get_record` does not connect, only resolves, so this is fine).
- **Credential theft if DB is breached** — encryption-at-rest reduces blast radius, but a key leak ends the game. Document the threat model in the deploy runbook. Key lives in env, not in repo or DB.
- **Reputation damage from organizer-sent spam** — if a malicious organizer uses our infra as a spam relay, complaints land at *their* configured mail provider, not ours. Net positive vs. status quo. Monitor: if abuse becomes a pattern, add per-user send-rate caps.
- **Verification email deliverability** — if the candidate DSN works but the `from_addr` domain isn't SPF/DKIM-authenticated for the SMTP host, the verification mail itself may land in spam. Help text surfaces this: "If no mail arrives, check your spam folder; your DSN's `from` domain may not be authenticated for the SMTP host."
- **Encryption key loss** — if `MAIL_CONFIG_ENCRYPTION_KEY` is lost, every saved organizer config is unrecoverable. The system continues to function (resolver falls back to platform when decrypt throws — covered by `OrganizerMailerResolver` catching `\SodiumException` and treating it as "no config" with an admin-visible error). Document key backup in the deploy runbook.
- **Race condition on simultaneous edits** — organizer editing their own config while a `ROLE_ADMIN` edits the same user. Last-write-wins; no optimistic lock. Acceptable for v1; flag for revisit if real-world UX shows churn.

## Out-of-scope explicit list (for the eventual implementation plan)

- No CLI command for key rotation.
- No CLI command for bulk-clear or re-verification.
- No webhook for bounces / complaints.
- No "test send to me" button distinct from verification. Verification *is* the test send.
- No per-event override.
- No multi-provider GUI.

## Acceptance

- [ ] `UserMailConfig` + `UserMailConfigAudit` entities exist; migration generated via `doctrine:migrations:diff` (not hand-written); `doctrine:schema:validate` green.
- [ ] `DsnVault`, `DsnValidator`, `OrganizerMailerResolver`, `TransportBuilder` services exist and are autowired.
- [ ] `MAIL_CONFIG_ENCRYPTION_KEY` documented in `.env` (placeholder) and deploy runbook (generation + backup instructions).
- [ ] Self-service routes work for an organizer end-to-end (save → verification email → click → verified → subsequent `forEvent` sends route through the custom transport).
- [ ] Admin routes work for `ROLE_ADMIN` editing another user.
- [ ] Audit rows written on every state change.
- [ ] All locked decisions enforced in code (scheme allow-list, host allow-list, hard-fail at send, raw-error UX, DB-first save ordering).
- [ ] Unit + integration + functional tests pass per the test plan above.
- [ ] PHPStan level 10 clean, `phpcs` PSR-12, GrumPHP green.
