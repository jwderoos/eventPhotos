# Notification confirm link: distinguish already-confirmed and timed-out states

Issue: #122
Date: 2026-07-24

## Problem

Visitors completing the "notify me when photos are live" double-opt-in
(`EventNotificationController::confirm()`, `GET /e/{slug}/notify/confirm/{token}`)
frequently land on **"This link is invalid or has expired"** on a legitimate
second tap. On mobile, double-tapping the confirm link (or an email client
pre-fetching it) is common.

Root cause: `confirm()` succeeds on the first tap, and
`EventNotificationSubscription::confirm()` sets `confirmationToken = null`
(`src/Entity/EventNotificationSubscription.php:111`). A repeat click of the
*correct* link then resolves to no row via
`EventNotificationSubscriptionRepository::findByConfirmationToken()` and falls
into the single OR'd guard at `EventNotificationController.php:120-124`, which
renders the generic `invalid` page.

Two distinct end-states are being collapsed into one page:

- **Already confirmed** — a repeat click of a link that already did its job.
  Recoverable: we just need the token to survive confirmation so the row can
  still be found.
- **Expired** — a pending subscription past its 7-day window
  (`DEFAULT_TTL_DAYS = 7`). Already distinguishable *today* — expiry does not
  null the token; only `confirm()`/`unsubscribe()` do — but the controller
  lumps it in with invalid.

Not recoverable (explicitly out of scope): a genuinely **mistyped/corrupted
token** matches no row, so it is indistinguishable from any other unknown
token and will always be the generic invalid page.

## Goal

Branch the confirm end-states into distinct, friendly pages:

| Lookup result                                   | Page shown                     |
|-------------------------------------------------|--------------------------------|
| No row (unknown / mistyped / stale-after-resignup token) | `invalid` (unchanged) |
| Status `Confirmed`                              | `confirmed` (idempotent re-tap) |
| Status `Pending` **and** expired                | `timed_out` (new)              |
| Status not `Pending` (i.e. `Unsubscribed`)      | `invalid`                      |
| Status `Pending`, not expired                   | `confirm()` → `confirmed`      |

Branch order is significant: `Confirmed` is checked **before** expiry so an
already-confirmed row never flips to "timed out."

## Changes

### 1. `EventNotificationSubscription::confirm()` (state machine)

Remove the single line `$this->confirmationToken = null;`
(`src/Entity/EventNotificationSubscription.php:111`). Continue nulling
`confirmationExpiresAt`. After confirmation the token is inert — the controller
returns the confirmed page before any state change — so it is a harmless
read-only bearer identifier.

Add a short comment explaining the retention, because it deliberately relaxes
the "only pending rows carry a live confirmation token" invariant noted in
`reconstituteForImport()`. Import is unaffected: its tokens are freshly minted
on import and never emailed, so their nulling for non-pending rows stays.

### 2. `EventNotificationController::confirm()` (branching)

Replace the combined guard (lines 120-124) with ordered branches implementing
the table above. Only call `$subscription->confirm($now)` in the final
`Pending`-and-not-expired case, so the entity's own guards
(`EventNotificationSubscription.php:101,105`) are never triggered as an error
path.

The `timed_out` render needs the event in context for the landing link; pass
`['event' => $subscription->getEvent()]` through `minimalPage()`.

### 3. Templates (`templates/public/event_notification/`)

- **New** `timed_out.html.twig`: "This link timed out" message plus a single
  **"Go to the event →"** button to `path('public_event_landing', {slug:
  event.slug})`. The landing page self-adjusts (signup form pre-publish, live
  gallery post-publish), so no publish-state branching lives in the notify
  controller.
- **`confirmed.html.twig`**: add the same **"Go to the event →"** link for
  consistency (needs `event` in context; pass it from both the happy-path and
  the idempotent-re-tap render).
- **`invalid.html.twig`**: unchanged.

All continue to route through `minimalPage()` (sets `Referrer-Policy:
no-referrer`).

## Non-changes

- **No migration.** `confirmationToken` and `confirmationExpiresAt` are already
  nullable; no columns added or altered.
- **No repository change.** `findByConfirmationToken` already returns rows
  regardless of status.
- Nothing else reads the token — verified: the only reference outside the
  entity is `findByConfirmationToken` (`EventNotificationSubscriptionRepository.php:30`).
  `SendEventLiveNotificationsHandler` selects by status, not token.

## Concurrency

Two simultaneous taps are benign. Each request loads its own entity instance.
Whichever commits second either (a) re-reads the row as `Confirmed` and returns
the confirmed page, or (b) in true concurrency re-writes status to `Confirmed`
on its own in-memory `Pending` entity — a harmless idempotent overwrite of
`confirmedAt`. No `DomainException` (each request's entity is `Pending` when
`confirm()` is called) and no unique-constraint violation.

## Testing (TDD, functional)

New cases in the notification confirm functional test:

- **Double confirm**: valid pending token → confirmed page + status
  `Confirmed`; a second GET on the same token → confirmed page again, HTTP 200,
  no error, status still `Confirmed`.
- **Expired**: a subscription whose `confirmationExpiresAt` is in the past
  (pending) → `timed_out` page, status remains `Pending`, and the page links to
  `public_event_landing`.

Existing happy-path and unknown-token → invalid assertions must continue to
pass.
