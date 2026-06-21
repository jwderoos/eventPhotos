# Visitor email signup for "photos are live" notification (#77)

**Status:** Design approved — pending spec review
**Date:** 2026-06-21
**Issue:** #77

## Summary

Let event visitors opt in (double opt-in) to a single "photos are live" email. When the
organizer publishes the event, every *confirmed* subscriber receives one email containing the
public event link, sent at a throttled rate to absorb the inbound traffic spike.

The feature is gated end-to-end on the organizer having a **verified custom mail transport**
(the `UserMailConfig` subsystem from #78/#87). Every email — confirmation *and* announcement —
is sent through the organizer's transport. **There is no platform-mail fallback anywhere in this
feature.**

## Foundational change: `OrganizerMailerResolver` becomes strict

The issue predates the per-organizer mail subsystem and assumed platform mail would be the sender.
That subsystem now exists, and #77's anti-abuse rules require that the feature is *never* available
without organizer mail and *never* falls back to platform mail.

Today `OrganizerMailerResolver::forEvent()` / `forUser()` silently return the platform mailer when
the organizer has no verified config. Those methods have **zero production callers** (only the
integration test and one functional test reference them), so the fallback is unused behavior that
sets a wrong precedent for future development. We fix the contract rather than work around it.

**New contract** — `forEvent(Event)` / `forUser(User)` always return the organizer's verified
custom `Mailer`, or throw. They never return the platform mailer.

| Condition | Old behavior | New behavior |
|---|---|---|
| No `UserMailConfig` / unverified | return platform mailer | throw `OrganizerMailNotConfiguredException` |
| Corrupted ciphertext (`SodiumException`) | log + return platform mailer | log + throw `OrganizerMailNotConfiguredException` |
| `DsnRejected` REASON_HOST (rebind) | auto-unverify + rethrow | **unchanged** (auto-unverify + rethrow) |
| Verified, valid DSN | return custom `Mailer` | **unchanged** |

- The `platformMailer` (`MailerInterface`) constructor dependency is removed from the resolver —
  nothing uses it anymore. Platform-level flows (invitations, password reset) already autowire
  `MailerInterface` directly and are unaffected.
- `isCustomActive(User): bool` is unchanged and is the gate callers use *before* calling the resolver.
- New exception `App\Service\Mail\OrganizerMailNotConfiguredException` (extends `\RuntimeException`).

**Doc impact:** `CLAUDE.md`'s "Per-organizer mail transport" paragraph states the `SodiumException`
fallback as intentional. Update it to reflect the strict contract (resolver hard-fails; corrupted
ciphertext now throws instead of falling back).

**Test impact:**
- `tests/Integration/Service/Mail/OrganizerMailerResolverTest.php`:
  `testReturnsPlatformMailerWhenUserHasNoConfig` and `testReturnsPlatformMailerWhenConfigIsUnverified`
  flip from asserting the platform mailer to `expectException(OrganizerMailNotConfiguredException::class)`.
  Add a test asserting corrupted ciphertext throws (no fallback).
- `tests/Functional/Admin/AccountMailFlowTest.php:288` exercises only the `DsnRejected` path — unchanged.

## Domain model

### New entity `EventNotificationSubscription` (`event_notification_subscriptions`)

bigint autoincrement id (project convention).

| column | type | notes |
|---|---|---|
| `id` | bigint PK | `GeneratedValue` |
| `event_id` | FK → `events` | `onDelete: CASCADE`, not nullable |
| `email` | varchar(255) | stored **lowercased** at construction |
| `confirmation_token` | varchar, nullable | base64url of `random_bytes(32)`; nulled once consumed |
| `unsubscribe_token` | varchar, not null | base64url of `random_bytes(32)`; long-lived |
| `status` | enum `EventNotificationStatus` | `pending` / `confirmed` / `unsubscribed` |
| `created_at` | timestamptz(0) | not null |
| `confirmation_expires_at` | timestamptz(0), nullable | = `created_at + 7 days` while pending; null once confirmed |
| `confirmed_at` | timestamptz(0), nullable | |
| `unsubscribed_at` | timestamptz(0), nullable | |
| `notified_at` | timestamptz(0), nullable | set when the live email is sent |

**Token storage decision:** lowercased `varchar` + unique index, **not** `citext` — keeps the
PostgreSQL-vs-MariaDB door open for #74.

**Constraints:**
- Unique `(event_id, email)` — one subscription per email per event (email already lowercased, so
  case-insensitivity is enforced by normalizing on write).
- Index `(event_id, status)` for the publish-time fan-out query.

**Domain methods** (guarded state machine, mirroring `Photo`; illegal transitions throw `DomainException`):
- `__construct(Event $event, string $email, DateTimeImmutable $now, int $ttlDays = 7)` — lowercases
  email, generates both tokens, status `pending`, sets `confirmation_expires_at`.
- `confirm(DateTimeImmutable $now)` — only from `pending` and only if not expired; → `confirmed`,
  sets `confirmed_at`, nulls `confirmation_token` and `confirmation_expires_at`.
- `unsubscribe(DateTimeImmutable $now)` — from `pending`/`confirmed`; → `unsubscribed`, sets
  `unsubscribed_at`.
- `restartPending(DateTimeImmutable $now, int $ttlDays = 7)` — reset an existing row (expired-pending
  or unsubscribed) back to `pending`: fresh `confirmation_token`, fresh `confirmation_expires_at`,
  clears `confirmed_at`/`unsubscribed_at`. Reuses the row (no new insert).
- `isConfirmationExpired(DateTimeImmutable $now): bool`.
- `markNotified(DateTimeImmutable $now)` — only from `confirmed`.

`EventNotificationStatus` is a string-backed enum.

### `Event` additions

- `published_at` (timestamptz(0), nullable) + one-shot `markPublished(DateTimeImmutable $now)` that
  throws `DomainException` if already set (mirrors `Photo` state-machine style). `isPublished(): bool`.
- `notificationsEnabled` (bool, default false) + `enableNotifications()` / `disableNotifications()`.

**`published_at` is only a notification milestone** — the `/e/{slug}` landing page is already public
and `markPublished()` does **not** gate photo visibility. It records "the live notification has been
sent" and flips the public signup form to a closed-notice.

Migrations generated via `doctrine:migrations:diff` (never hand-written).

### Repository `EventNotificationSubscriptionRepository`

- `findOneByEventAndEmail(Event, string $email): ?EventNotificationSubscription` (lowercases input).
- `findByConfirmationToken(string $token): ?…` and `findByUnsubscribeToken(string $token): ?…`.
- `findConfirmedByEvent(Event): iterable<…>` for the fan-out (uses the `(event_id, status)` index).
- `countByEvent(Event): int` for the admin subscriber-count display.

## Public flow — `Public\EventNotificationController`

All three routes are anonymous and **stateless — must not touch the session** (#68 audit pattern;
re-verify with `grep -rn "addFlash\|getSession\|csrf_token" src/Controller/Public/ templates/public/`
after implementation). Confirmation/unsubscribe pages are minimal and carry
`Referrer-Policy: no-referrer`.

### `POST /e/{slug}/notify` → `subscribe`

Rate-limited + honeypot. **Always returns an identical response regardless of email state**
(enumeration-safe): the same neutral "check your inbox to confirm" page/flash-free 200.

1. **Honeypot:** if the hidden `website` field is non-empty → return the standard 200 and drop silently.
2. **IP limiter:** `visitor_email_signup` (5/hour/IP). On reject → 429.
3. Resolve event by slug (404 if missing). If `notificationsEnabled` is false, owner mail is not
   active (`isCustomActive` false), or the event is already published → return the standard 200
   without creating anything (no signup possible; still no state leak).
4. Lowercase + validate the email (`Assert\Email`). On invalid → standard 200 (no leak).
5. Look up the existing `(event, email)` row and apply the **re-subscribe matrix**:

| Existing row | Action | Confirmation email |
|---|---|---|
| none | create `pending` | send (per-email throttle) |
| `pending`, expired | `restartPending()` | send (per-email throttle) |
| `pending`, not expired | resend (no state change) | send (per-email throttle) |
| `confirmed` | **no-op, no state change** | **none** (closes the "spray a confirmed inbox" mail-bomb) |
| `unsubscribed` | `restartPending()` | send (per-email throttle) |

6. **Per-email throttle:** before sending any confirmation, consume the `confirm_email_resend`
   limiter (1/10 min) keyed on the lowercased email. If rejected, persist the row but skip the send
   (still standard 200). This guards a single inbox against distributed (IP-rotating) mail-bombing.
7. **Confirmation send (inline, caught):** v1 sends the confirmation synchronously via
   `resolver->forEvent($event)`, wrapped in try/catch so a transport failure logs but does **not**
   500 the visitor — still return the standard 200. A single confirmation is cheap, and inline
   sending keeps the persisted row and its freshly-issued token consistent with what was mailed.
   (The publish fan-out is the only async mail path.)

### `GET /e/{slug}/notify/confirm/{token}` → `confirm`

- Resolve by `confirmation_token`. If not found **or** `isConfirmationExpired($now)` → render the
  same generic "this link is invalid or has expired" page (expired == nonexistent; no cleanup service
  in v1). No email, no state leak beyond the generic page.
- Else `confirm($now)` (nulls the token — single-use; a reused token now resolves to nothing) and
  render a "you're confirmed" page.

### `GET /e/{slug}/notify/unsubscribe/{token}` → `unsubscribe`

- Resolve by `unsubscribe_token` (long-lived; works pre- and post-publish). If found and not already
  unsubscribed → `unsubscribe($now)`. Always render a generic "you've been unsubscribed" page.

## Admin flow — `Admin\EventController`

### Enable-notifications toggle

- Offered on the admin event page **only when** `isCustomActive($event->getOwner())`. When mail is
  not active, show an inline hint linking to mail setup instead of the toggle.
- POST endpoint guarded by `EventVoter::EDIT` + CSRF, flips `notificationsEnabled`.

### `POST /admin/events/{id}/publish` → `publish`

Guarded by `EventVoter::EDIT` + CSRF (`isCsrfTokenValid`).

Preconditions (all required; button disabled in UI and re-checked server-side):
- event not already published (`markPublished` is one-shot and throws otherwise),
- at least one photo in `PhotoStatus::Ready`,
- `isCustomActive($event->getOwner())` (can't notify without an active transport).

On success: `markPublished($now)`, flush, dispatch `SendEventLiveNotifications($eventId)` to `async`.

The confirm dialog shows the projected duration: `ceil(confirmedCount / rate)` minutes
("Notifying N subscribers — sends will complete in ~M minutes").

The admin page shows **subscriber count only** (`countByEvent`) — no email list, no PII.

## Fan-out — Messenger

Both messages routed to the existing `async` Doctrine transport with the same retry policy as
`ProcessPhoto` (3× backoff → `failed`).

### `SendEventLiveNotifications(int $eventId)` + handler

- No-op if the event is missing or not published (idempotent).
- Read rate from `EVENT_LIVE_NOTIFICATION_RATE_PER_MIN` (default **30**), injected via
  `#[Autowire('%env(int:EVENT_LIVE_NOTIFICATION_RATE_PER_MIN)%')]`.
- Query confirmed subscribers (ordered by id for deterministic delay assignment). For each index `i`,
  dispatch `SendEventLiveEmail($subscriptionId)` with `DelayStamp((int) ($i * 60000 / $rate))`.
- One Messenger message per recipient so a single bad address can't block the batch.

### `SendEventLiveEmail(int $subscriptionId)` + handler

- Load subscription; no-op unless still `confirmed` and `notified_at` is null (idempotent —
  Messenger may redeliver).
- Resolve `$mailer = $resolver->forEvent($event)` — **strict**: if the transport vanished
  (unverified / rebind / corrupted), this throws and the message hard-fails into the retry/dead-letter
  path. No platform fallback.
- Build a `TemplatedEmail` (live template) `->to($subscription->getEmail())` with the public event
  URL and the unsubscribe URL in context. From-address comes from the organizer transport's own
  identity (the configured `UserMailConfig` from-address) — **no `MAIL_FROM` env** (a platform
  from-address would contradict "never platform"; the issue's `MAIL_FROM` line is dropped).
- On success `markNotified($now)`, flush.

## Mail templates

`templates/email/event-notification/`:
- `confirm.html.twig` + `confirm.txt.twig` — context: confirm URL, unsubscribe URL. Carries
  `<meta name="referrer" content="no-referrer">` like the existing mail-config template.
- `live.html.twig` + `live.txt.twig` — context: event name, public event URL, unsubscribe URL.

Self-contained (do not extend `base.html.twig`), matching the existing `mail-config/verify` templates.

## Rate limiters — `config/packages/rate_limiter.yaml`

```yaml
visitor_email_signup:
    policy: 'sliding_window'
    limit: 5
    interval: '1 hour'
    cache_pool: 'rate_limiter.cache_pool'

confirm_email_resend:
    policy: 'sliding_window'
    limit: 1
    interval: '10 minutes'
    cache_pool: 'rate_limiter.cache_pool'
```

Injected into the controller via `#[Autowire(service: 'limiter.visitor_email_signup')]` /
`limiter.confirm_email_resend` as `RateLimiterFactoryInterface`. The IP limiter is keyed on client IP;
the resend limiter on the lowercased email. These are separate from the existing
`PublicRateLimitListener` (which covers GET landing/photos/display routes, not this POST).

## Public page integration (`templates/public/`)

On `/e/{slug}`:
- If `notificationsEnabled && isCustomActive(owner) && !isPublished` → render the signup form
  (email input + hidden `website` honeypot + submit). No CSRF token (sessionless).
- If `isPublished` → render a small "Notifications already sent for this event" notice instead.
- Otherwise → render nothing.

## Configuration / `.env`

- `EVENT_LIVE_NOTIFICATION_RATE_PER_MIN=30` — documented in `.env`.
- No `MAILER_DSN` / `MAIL_FROM` work required: this feature sends exclusively through the organizer
  transport. (The platform `MAILER_DSN` remains relevant only to invitations/password-reset, out of
  scope here.)

## Testing

**Unit**
- `EventNotificationSubscription`: construct → pending; confirm (happy + rejected when expired +
  rejected when not pending); unsubscribe; `restartPending` from expired-pending and from
  unsubscribed; `markNotified` guard; `isConfirmationExpired` boundary (exactly 7 days).
- Token generator: format (urlsafe, no padding) and length/entropy (32 bytes).
- `Event::markPublished` one-shot (second call throws); `isPublished`.

**Integration**
- Unique `(event_id, email)` enforced case-insensitively (insert `A@x` then `a@x` → reuse/violation).
- Re-subscribe after unsubscribe reuses the same row id.
- `OrganizerMailerResolver`: throws `OrganizerMailNotConfiguredException` when no/unverified config;
  throws on corrupted ciphertext; returns custom mailer when verified (existing test); rebind path
  unchanged.

**Functional** (in-memory mailer assertions — `assertEmailCount`, `getMailerMessages`; no real SMTP)
- Happy path: enable → signup → confirmation email rendered with confirm link → confirm → publish →
  one live email per confirmed subscriber with the correct public link + unsubscribe link.
- Honeypot non-empty → 200 OK, zero emails, zero rows.
- `visitor_email_signup` limiter trips on the 6th signup within the hour.
- `confirm_email_resend` limiter: 2nd confirmation to the same address within 10 min is suppressed.
- **Confirmed re-submit → 200 OK, no email, no state change.**
- CSRF rejection on `publish`.
- Publish rejected without any `Ready` photo.
- Publish rejected when owner mail is not active.
- Expired confirmation token → invalid page, no confirmation.
- Reused (already-consumed) confirmation token → invalid page.
- Unsubscribe token works post-publish.
- Signup form/notify endpoint unavailable when `notificationsEnabled` false or owner mail inactive
  or event already published.
- N confirmed subscribers → exactly N `SendEventLiveEmail` messages dispatched with monotonically
  increasing `DelayStamp` values matching `i * 60000 / rate`.
- **Session audit:** the three public routes create zero `sessions` rows (assert via the #68 grep
  pattern / no `Set-Cookie`).

## Out of scope (per issue)

Re-publish / "more photos" follow-ups; `EventCollection`-level subscriptions; visitor accounts;
admin view of subscriber identities; inline preview thumbnails in the email; a pending-cleanup
command (expiry is enforced lazily via `confirmation_expires_at`; a cron can be added later if rows
accumulate).
