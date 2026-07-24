# #125 — Email signup system improvements

**Issue:** [#125](https://github.com/jwderoos/eventPhotos/issues/125)
**Coordinates with:** [#123](https://github.com/jwderoos/eventPhotos/issues/123) (console re-send + observability — separate scope)
**Date:** 2026-07-24

## Summary

Three independent enhancements to the visitor double-opt-in "notify me when photos
are live" flow shipped in #77:

1. Surface **confirmed vs. total** signup counts to the organizer.
2. Send the double-opt-in **confirmation email asynchronously** via Messenger
   (today it is sent inline in the web request).
3. Give the organizer a **dashboard action to re-send** the confirmation to
   `Pending` (unverified) subscribers only.

**No schema change** → no migration. Per-subscription send-outcome tracking
(`send_attempted_at`) is deliberately left to #123.

## Design decisions (resolved during brainstorming)

- **Email construction is extracted into a shared `EventStyledEmailFactory`.** The
  issue text names this factory; it did not exist. Both the current confirmation
  email (built inline in `EventNotificationController::sendConfirmation()`) and the
  live-announcement email (built inline in `SendEventLiveEmailHandler`) are moved
  onto it. The factory centralises *construction* (from/to/subject/templates/URL
  context) — it does **not** add a styling layer; the current email templates are
  plain inline-HTML and stay as-is.
- **Item 3 uses an in-request service** (`PendingConfirmationResender`), not an
  orchestrator message. Pending sets pre-publish are small, the restart+flush is
  cheap, and the actual sends are already async. #123's console command reuses the
  same service.
- **The dashboard re-send bypasses the per-email `confirm_email_resend` limiter.**
  It is an authenticated, CSRF-protected, `EventVoter::EDIT`-gated organizer action
  — deliberate, not abuse. SMTP-cap pacing (`DelayStamp`) is the only throttle.
- **Audit logging of the re-send is out of scope.** The service logs each send
  outcome (satisfies the issue's "log each send outcome" checkbox).

## Single send path

All three surfaces converge on one async send unit:

```
signup (item 2)      ─┐
dashboard re-send    ─┼─► dispatch SendSubscriptionConfirmationEmail(subscriptionId)
console re-send(#123) ┘        └─► SendSubscriptionConfirmationEmailHandler
                                        └─► EventStyledEmailFactory::confirmation()
                                        └─► OrganizerMailerResolver::forEvent()->send()
```

---

## Item 1 — Confirmed vs. total counts

### Repository
`EventNotificationSubscriptionRepository::countConfirmedByEvent(Event): int` —
COUNT-by-status, mirroring the existing `countByEvent()`:

```php
public function countConfirmedByEvent(Event $event): int
{
    return (int) $this->createQueryBuilder('s')
        ->select('COUNT(s.id)')
        ->andWhere('s.event = :event')
        ->andWhere('s.status = :status')
        ->setParameter('event', $event)
        ->setParameter('status', EventNotificationStatus::Confirmed)
        ->getQuery()
        ->getSingleScalarResult();
}
```

### Controller
`Admin\EventController::edit()` — replace
`count($this->subscriptions->findConfirmedByEvent($event))` (full hydration) with
`countConfirmedByEvent($event)`. Reuse the result for both `projectedMinutes` and a
new `confirmedCount` template var. `findConfirmedByEvent()` stays — it still feeds
the publish fan-out in `SendEventLiveNotificationsHandler`.

### Template
`templates/admin/event/form.html.twig`, Visitor notifications section — change
`"{{ subscriberCount }} subscriber(s) so far."` to
`"{{ confirmedCount }} confirmed of {{ subscriberCount }} total signup(s)."`

---

## Item 2 — Async confirmation email

### `App\Service\Mail\EventStyledEmailFactory`
Injects `UrlGeneratorInterface`. Two methods, each returning a `TemplatedEmail`:

- `confirmation(Event $event, EventNotificationSubscription $sub, UserMailConfig $config): TemplatedEmail`
  — from `$config->getSenderAddress()`, to `$sub->getEmail()`, subject
  `"Confirm notifications for <name>"`, templates
  `email/event-notification/confirm.{html,txt}.twig`, context
  `{ eventName, confirmUrl, unsubscribeUrl }`.
- `liveAnnouncement(Event $event, EventNotificationSubscription $sub, UserMailConfig $config): TemplatedEmail`
  — subject `"Photos from <name> are live"`, templates
  `email/event-notification/live.{html,txt}.twig`, context
  `{ eventName, eventUrl, unsubscribeUrl }`.

Callers keep the null-`UserMailConfig` guard and pass a non-null config in, so the
factory stays pure (no null handling, PHPStan-clean).

### `App\Message\SendSubscriptionConfirmationEmail`
```php
final readonly class SendSubscriptionConfirmationEmail
{
    public function __construct(public int $subscriptionId) {}
}
```
Routed `async` in `config/packages/messenger.yaml`.

### `App\MessageHandler\SendSubscriptionConfirmationEmailHandler`
`#[AsMessageHandler] final readonly`. Mirrors `SendEventLiveEmailHandler`:

1. `find($message->subscriptionId)`; return if `null` or
   `status !== EventNotificationStatus::Pending` (idempotency — a subscriber who
   confirmed between dispatch and handling is skipped).
2. `getMailConfig()` null → return (no-op).
3. `mailerResolver->forEvent($event)` — **strict**: a throw hard-fails the message
   into Messenger retry/DLQ. No platform-mail fallback.
4. Build via `EventStyledEmailFactory::confirmation()`, `send()`.
5. **No** `markNotified()` (that flag is live-announcement-only).

### Controller
`Public\EventNotificationController::subscribe()` — replace the
`$this->sendConfirmation($event, $subscription)` call with
`$this->bus->dispatch(new SendSubscriptionConfirmationEmail((int) $subscription->getId()))`.
The `confirmResendLimiter` gate stays at the controller (now gates *dispatch*, not
send). The subscription is already flushed before this point, so the id is set.

Remove the dead `sendConfirmation()` private method and its now-unused
dependencies/imports (`LoggerInterface`, `TemplatedEmail`, `UrlGeneratorInterface`,
`UserMailConfig`, `Throwable`). Add `MessageBusInterface $bus`.
`OrganizerMailerResolver` stays — still used for `isCustomActive()`.

`SendEventLiveEmailHandler` is refactored to build its email via
`EventStyledEmailFactory::liveAnnouncement()` (proves the factory is genuinely
shared; behaviour unchanged).

---

## Item 3 — Dashboard re-send to Pending

### Repository
- `findPendingByEvent(Event): array<int, EventNotificationSubscription>` — mirrors
  `findConfirmedByEvent()` with `status = Pending`, ordered by `id ASC`.
- `countPendingByEvent(Event): int` — COUNT-by-status, for the button label/gate.

### `App\Service\Notification\PendingConfirmationResender`
Constructor: `EventNotificationSubscriptionRepository`, `EntityManagerInterface`,
`MessageBusInterface`, `LoggerInterface`,
`#[Autowire('%env(int:EVENT_LIVE_NOTIFICATION_RATE_PER_MIN)%')] int $ratePerMinute`.

```php
public function resendAll(Event $event): int
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $pending = $this->subscriptions->findPendingByEvent($event);

    foreach ($pending as $subscription) {
        $subscription->restartPending($now); // fresh token + expiry
    }
    $this->em->flush();

    $rate = max(1, $this->ratePerMinute);
    $intervalMs = intdiv(60_000, $rate);
    $index = 0;
    foreach ($pending as $subscription) {
        $this->bus->dispatch(
            new SendSubscriptionConfirmationEmail((int) $subscription->getId()),
            [new DelayStamp($index * $intervalMs)],
        );
        $this->logger->info('Re-queued confirmation for pending subscriber.', [
            'event_id' => $event->getId(),
            'subscription_id' => $subscription->getId(),
        ]);
        ++$index;
    }

    return count($pending);
}
```

Pacing (`intdiv(60_000, $rate)`) mirrors `SendEventLiveNotificationsHandler` so
re-sends respect the organizer's SMTP caps.

### Route + controller
`POST /admin/events/{id}/notify/resend-pending`, name
`admin_event_notify_resend_pending`, in `Admin\EventController`:

- `denyAccessUnlessGranted(EventVoter::EDIT, $event)`.
- CSRF token `resend_pending_<id>`.
- Guard: reject (redirect + flash error) when `$event->isPublished()` or
  `!$mailerResolver->isCustomActive($event->getOwner())` — a re-send after publish
  can't help (announcement already went to Confirmed only).
- `$count = $resender->resendAll($event)`; flash
  `"Re-sent confirmation to N unverified subscriber(s)."`; redirect to
  `admin_event_edit`.

### Template
`templates/admin/event/form.html.twig`, Visitor notifications section — add, shown
when `not event.isPublished and mailActive and pendingCount > 0`:

```twig
<form method="post" action="{{ path('admin_event_notify_resend_pending', {id: event.id}) }}">
    <input type="hidden" name="_token" value="{{ csrf_token('resend_pending_' ~ event.id) }}">
    <button type="submit" class="btn btn-outline btn-sm w-fit">
        Re-send confirmation to {{ pendingCount }} unverified subscriber(s)
    </button>
</form>
```

`edit()` passes `pendingCount = countPendingByEvent($event)`.

---

## Testing

- **`EventStyledEmailFactory`** (unit): both methods produce the right subject,
  html/text templates, and context keys.
- **`SendSubscriptionConfirmationEmailHandler`** (integration/functional): Pending →
  one email sent; non-Pending → no-op; null `UserMailConfig` → no-op; resolver throw
  → exception propagates (retry path).
- **`PendingConfirmationResender`** (integration): only Pending rows restarted (fresh
  token, `Pending` status, cleared timestamps); Confirmed/Unsubscribed untouched; one
  `SendSubscriptionConfirmationEmail` dispatched per Pending row; return count correct.
- **`EventNotificationController::subscribe()`** (functional): now *dispatches*
  `SendSubscriptionConfirmationEmail` (assert on the in-memory transport) instead of
  sending inline; limiter still gates.
- **Dashboard re-send route** (functional): CSRF required; `EventVoter::EDIT` enforced;
  blocked when published / mail inactive; dispatches per Pending row.
- **Edit page** (functional): renders `"N confirmed of M total signup(s)."`
- **Repository** (integration): `countConfirmedByEvent`, `countPendingByEvent`,
  `findPendingByEvent` return correct sets/counts across mixed statuses.

## Files touched

**New**
- `src/Service/Mail/EventStyledEmailFactory.php`
- `src/Message/SendSubscriptionConfirmationEmail.php`
- `src/MessageHandler/SendSubscriptionConfirmationEmailHandler.php`
- `src/Service/Notification/PendingConfirmationResender.php`
- tests for each of the above

**Modified**
- `src/Repository/EventNotificationSubscriptionRepository.php` (3 new methods)
- `src/Controller/Public/EventNotificationController.php` (dispatch, drop inline send)
- `src/MessageHandler/SendEventLiveEmailHandler.php` (use factory)
- `src/Controller/Admin/EventController.php` (count query, re-send route, template vars)
- `templates/admin/event/form.html.twig` (counts copy, re-send button)
- `config/packages/messenger.yaml` (route new message)
```
