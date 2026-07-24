# Notify-confirm idempotent end-states — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the notification confirm link idempotent so a repeat tap shows "You're confirmed" and an expired link shows "This link timed out", instead of collapsing both into the generic "invalid" page.

**Architecture:** Two changes. (1) `EventNotificationSubscription::confirm()` stops nulling `confirmationToken`, so a confirmed row is still findable by token. (2) `EventNotificationController::confirm()` replaces its single OR'd guard with ordered status/expiry branches routing to distinct templates. No schema change.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, PHPUnit 13, Twig, Tailwind.

Issue: #122 · Branch: `feature/122-notify-confirm-idempotent-states` (already created) · Spec: `docs/superpowers/specs/2026-07-24-122-notify-confirm-idempotent-states-design.md`

## Global Constraints

- PHP attributes only, no annotations.
- Quality gates must pass (`vendor/bin/grumphp run`): phpstan level 10, phpcs PSR-12, phpmnd (no magic numbers in `src/`), phpcpd, rector, full phpunit, `doctrine:schema:validate`.
- **No migration** — `confirmationToken` / `confirmationExpiresAt` are already nullable; no columns change.
- **Do not run `git commit`.** The user commits. Each task ends by running grumphp, staging, and proposing a one-line commit message containing `#122`.
- Branch stays `feature/122-notify-confirm-idempotent-states` throughout.

---

### Task 1: Retain the confirmation token on confirm (entity + unit test)

**Files:**
- Modify: `src/Entity/EventNotificationSubscription.php:99-113` (`confirm()`)
- Modify: `tests/Unit/Entity/EventNotificationSubscriptionTest.php:41-50` (existing `testConfirmFromPendingClearsToken`)

**Interfaces:**
- Consumes: nothing.
- Produces: `EventNotificationSubscription::confirm(DateTimeImmutable $now): void` — after this call `getStatus() === Confirmed`, `getConfirmationToken()` returns the SAME non-null token it had before confirm, and `isConfirmationExpired()` returns false (its `confirmationExpiresAt` is still nulled).

- [ ] **Step 1: Update the existing unit test to assert the token is retained**

In `tests/Unit/Entity/EventNotificationSubscriptionTest.php`, rename `testConfirmFromPendingClearsToken` and rewrite its body to capture the token before confirm and assert it is unchanged after:

```php
public function testConfirmFromPendingRetainsToken(): void
{
    $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));
    $tokenBeforeConfirm = $sub->getConfirmationToken();

    $sub->confirm($this->at('2026-06-22 10:00:00'));

    $this->assertSame(EventNotificationStatus::Confirmed, $sub->getStatus());
    $this->assertNotNull($sub->getConfirmationToken());
    $this->assertSame($tokenBeforeConfirm, $sub->getConfirmationToken());
    $this->assertFalse($sub->isConfirmationExpired($this->at('2026-07-01 10:00:00')));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter testConfirmFromPendingRetainsToken`
Expected: FAIL — `assertNotNull`/`assertSame` fail because `confirm()` currently sets the token to null.

- [ ] **Step 3: Remove the token-null line in `confirm()`**

In `src/Entity/EventNotificationSubscription.php`, `confirm()` currently reads:

```php
        $this->status = EventNotificationStatus::Confirmed;
        $this->confirmedAt = $now;
        $this->confirmationToken = null;
        $this->confirmationExpiresAt = null;
```

Delete the `$this->confirmationToken = null;` line and add a comment. It becomes:

```php
        $this->status = EventNotificationStatus::Confirmed;
        $this->confirmedAt = $now;
        // Token is retained (not nulled) so a repeat tap of the confirm link
        // still resolves this row and can render the idempotent "confirmed"
        // page instead of the generic "invalid" page. It is inert after
        // confirmation — the confirm controller returns before any state
        // change. See #122. (reconstituteForImport still nulls it for
        // non-pending imports, whose tokens are freshly minted and never sent.)
        $this->confirmationExpiresAt = null;
```

- [ ] **Step 4: Run the entity test class to verify it passes and nothing regressed**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventNotificationSubscriptionTest.php`
Expected: PASS — including `testRestartPendingFromUnsubscribedIssuesFreshToken` (unsubscribe still nulls the token) and `testConfirmRejectedWhenNotPending` (double-confirm on the same entity still throws `DomainException`).

- [ ] **Step 5: Verify, stage, propose commit**

Run: `vendor/bin/grumphp run`
Expected: all tasks green.
Then `git add src/Entity/EventNotificationSubscription.php tests/Unit/Entity/EventNotificationSubscriptionTest.php` and propose the commit message (do not run `git commit`):
`#122 - retain confirmation token after confirm so repeat taps are idempotent`

---

### Task 2: Branch the confirm controller into confirmed / timed-out / invalid pages

**Files:**
- Modify: `src/Controller/Public/EventNotificationController.php:114-132` (`confirm()`)
- Create: `templates/public/event_notification/timed_out.html.twig`
- Modify: `templates/public/event_notification/confirmed.html.twig` (add landing link)
- Modify: `tests/Functional/Public/EventNotificationControllerTest.php` (two new tests)

**Interfaces:**
- Consumes: Task 1's token-retaining `confirm()`. Existing `EventNotificationSubscriptionRepository::findByConfirmationToken(string): ?EventNotificationSubscription`, `EventNotificationSubscription::getStatus()`, `::isConfirmationExpired(DateTimeImmutable)`, `Event::getSlug()`, and the `public_event_landing` route (`/e/{slug}`).
- Produces: nothing downstream.

- [ ] **Step 1: Write the two failing functional tests**

Add to `tests/Functional/Public/EventNotificationControllerTest.php` (uses existing `makeEventWithMail` helper and `Request`/`DateTimeImmutable`/`DateTimeZone` imports already in the file):

```php
public function testDoubleConfirmShowsConfirmedPageAgain(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);
    $event = $this->makeEventWithMail($em, 'double-confirm-event');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $sub = new EventNotificationSubscription($event, 'd@example.com', $now);
    $token = (string) $sub->getConfirmationToken();
    $em->persist($sub);
    $em->flush();

    $client->request(Request::METHOD_GET, '/e/double-confirm-event/notify/confirm/' . $token);
    self::assertResponseIsSuccessful();
    self::assertSelectorTextContains('body', 'confirmed');

    // Second tap of the SAME link — must not fall into the invalid page.
    $client->request(Request::METHOD_GET, '/e/double-confirm-event/notify/confirm/' . $token);
    self::assertResponseIsSuccessful();
    self::assertSelectorTextContains('body', 'confirmed');

    $em->clear();
    /** @var EventNotificationSubscriptionRepository $repo */
    $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
    /** @var Event $reloadedEvent */
    $reloadedEvent = $em->getRepository(Event::class)->findOneBy(['slug' => 'double-confirm-event']);
    $reloaded = $repo->findOneByEventAndEmail($reloadedEvent, 'd@example.com');
    $this->assertInstanceOf(EventNotificationSubscription::class, $reloaded);
    $this->assertSame(EventNotificationStatus::Confirmed, $reloaded->getStatus());
}

public function testExpiredConfirmTokenShowsTimedOutPage(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);
    $event = $this->makeEventWithMail($em, 'timed-out-event');
    // createdAt 8 days ago -> confirmationExpiresAt (createdAt + 7 days) is in the past.
    $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-8 days');
    $sub = new EventNotificationSubscription($event, 't@example.com', $createdAt);
    $token = (string) $sub->getConfirmationToken();
    $em->persist($sub);
    $em->flush();

    $client->request(Request::METHOD_GET, '/e/timed-out-event/notify/confirm/' . $token);
    self::assertResponseIsSuccessful();
    self::assertSelectorTextContains('body', 'timed out');

    $em->clear();
    /** @var EventNotificationSubscriptionRepository $repo */
    $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
    /** @var Event $reloadedEvent */
    $reloadedEvent = $em->getRepository(Event::class)->findOneBy(['slug' => 'timed-out-event']);
    $reloaded = $repo->findOneByEventAndEmail($reloadedEvent, 't@example.com');
    $this->assertInstanceOf(EventNotificationSubscription::class, $reloaded);
    $this->assertSame(EventNotificationStatus::Pending, $reloaded->getStatus());
}
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'testDoubleConfirmShowsConfirmedPageAgain|testExpiredConfirmTokenShowsTimedOutPage'`
Expected: FAIL — double-confirm's second request renders "invalid" (no "confirmed" text); the expired test renders "invalid" and the `timed_out.html.twig` template does not yet exist.

- [ ] **Step 3: Create the timed-out template**

Create `templates/public/event_notification/timed_out.html.twig`:

```twig
{% extends 'public/_base.html.twig' %}
{% block public_main %}
    <main class="mx-auto max-w-md p-6 text-center">
        <h1 class="text-xl font-semibold">This link timed out</h1>
        <p class="mt-2">Your confirmation link has expired.</p>
        <a class="mt-4 inline-block underline" href="{{ path('public_event_landing', {slug: event.slug}) }}">Go to the event &rarr;</a>
    </main>
{% endblock %}
```

- [ ] **Step 4: Add the landing link to the confirmed template**

Replace `templates/public/event_notification/confirmed.html.twig` with:

```twig
{% extends 'public/_base.html.twig' %}
{% block public_main %}
    <main class="mx-auto max-w-md p-6 text-center">
        <h1 class="text-xl font-semibold">You're confirmed</h1>
        <p class="mt-2">We'll email you once the photos are live.</p>
        <a class="mt-4 inline-block underline" href="{{ path('public_event_landing', {slug: event.slug}) }}">Go to the event &rarr;</a>
    </main>
{% endblock %}
```

- [ ] **Step 5: Rewrite the controller `confirm()` branches**

In `src/Controller/Public/EventNotificationController.php`, replace the body of `confirm()` (lines 114-132) with ordered branches. Capture the event from `resolve()` and pass it to the confirmed/timed-out renders:

```php
public function confirm(string $slug, string $token): Response
{
    $event = $this->resolve($slug);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $subscription = $this->subscriptions->findByConfirmationToken($token);

    if (!$subscription instanceof EventNotificationSubscription) {
        return $this->minimalPage('public/event_notification/invalid.html.twig');
    }

    // Idempotent: a repeat tap of an already-used (but valid) confirm link.
    if ($subscription->getStatus() === EventNotificationStatus::Confirmed) {
        return $this->minimalPage('public/event_notification/confirmed.html.twig', ['event' => $event]);
    }

    // Unsubscribed (or any other non-pending state) → generic invalid.
    if ($subscription->getStatus() !== EventNotificationStatus::Pending) {
        return $this->minimalPage('public/event_notification/invalid.html.twig');
    }

    // Pending but past the confirmation window → timed-out page.
    if ($subscription->isConfirmationExpired($now)) {
        return $this->minimalPage('public/event_notification/timed_out.html.twig', ['event' => $event]);
    }

    $subscription->confirm($now);
    $this->em->flush();

    return $this->minimalPage('public/event_notification/confirmed.html.twig', ['event' => $event]);
}
```

Note: `resolve()` already returns the `Event` (it was previously called without capturing the return). No new imports needed — `EventNotificationStatus`, `DateTimeImmutable`, `DateTimeZone`, `EventNotificationSubscription` are already imported.

- [ ] **Step 6: Run the new tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'testDoubleConfirmShowsConfirmedPageAgain|testExpiredConfirmTokenShowsTimedOutPage'`
Expected: PASS.

- [ ] **Step 7: Run the full notification functional test class**

Run: `vendor/bin/phpunit tests/Functional/Public/EventNotificationControllerTest.php`
Expected: PASS — including the existing `testConfirmTokenConfirms` and `testInvalidConfirmTokenShowsInvalidPage`.

- [ ] **Step 8: Verify, stage, propose commit**

Run: `vendor/bin/grumphp run`
Expected: all tasks green (full phpunit + phpstan + phpcs + rector + schema-validate).
Then `git add src/Controller/Public/EventNotificationController.php templates/public/event_notification/ tests/Functional/Public/EventNotificationControllerTest.php` and propose the commit message (do not run `git commit`):
`#122 - confirm link: show confirmed page on repeat tap, timed-out page on expiry`

---

## Verification (end-to-end)

1. **Automated:** `vendor/bin/grumphp run` green (runs the full suite + all gates).
2. **Manual happy path + double-tap:** with the stack up (`docker compose up -d`), create an event with notifications enabled and a verified organizer mail config, sign up a visitor email, grab the confirmation URL from the sent mail (or Mailpit), open it → "You're confirmed" with a working "Go to the event →" link. Open the same URL again → still "You're confirmed" (not "invalid").
3. **Manual expiry:** set a subscription's `confirmation_expires_at` to a past timestamp (or seed one via a `createdAt` 8+ days ago), open its confirm URL → "This link timed out" with the landing link.
4. **Regression:** open a confirm URL with a garbage token → still "This link is invalid or has expired".

## Notes / non-goals

- A genuinely mistyped/corrupted token matches no row and remains the generic invalid page — unrecoverable by construction, out of scope.
- Unsubscribed-then-reclick stays on the invalid page (unsubscribe nulls the token); handling it was explicitly out of scope.
- Import (`reconstituteForImport`) is unchanged.
