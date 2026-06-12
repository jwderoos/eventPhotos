# Display Mode Window Gating — Design

**Issue:** [#46](https://github.com/jwderoos/eventPhotos/issues/46)
**Date:** 2026-06-12

## Goal

`/e/{slug}/display` currently shows the same live, `now`-anchored QR regardless of where `now` falls relative to the event window. That's wrong in two ways: before the event the QR points at a moment with no photos, and after the event the QR keeps minting fresh `t` values for a period when no more uploads happen. This design gates the page by event window with three distinct states and adds page-self-healing across transitions so an unattended venue screen "just works" from setup through tear-down.

## Background

- `Public\EventController::display()` (`src/Controller/Public/EventController.php:92`) renders `templates/public/event/display.html.twig` with a single live-anchored QR baked in. Refresh endpoint `displayQr()` (same file, line 115) returns SVG-only.
- The Stimulus controller `assets/controllers/qr_refresh_controller.js` polls the refresh endpoint every 60 s, swaps the SVG into the page, and re-formats the "Updated HH:mm" timestamp from the local clock.
- `PhotosUrlBuilder::build($event, $when, $absolute)` formats `t` via `$when->format('H:i')`, so the caller must pass a `DateTimeImmutable` already rebased to the event's timezone (the controller does this for `now` via `nowInEventTimezone()`).
- Post #45, `Event::startsAt` and `Event::endsAt` are non-null. This design assumes that and does not implement a null branch.
- `Event` already has a precedent for state/derivation methods on the entity: `Event::resolveWindowMinutes()`.

## Scope

### In scope

- Three display states (Pre, Live, Post) with the boundary rule from the issue: `[startsAt, endsAt]` inclusive → Live; strictly before `startsAt` → Pre; strictly after `endsAt` → Post.
- Pre-event: static QR pointing at `/e/{slug}/photos?t=<startsAt-HH:mm in event TZ>`.
- Live: existing behaviour preserved (live QR anchored to `now`, 60 s refresh).
- Post: no QR; "this event has ended" message in the same layout.
- Always render a visible textual photos URL beneath the QR in pre and live states (matches the user-approved UX option C). The URL is also the `href` of an anchor — testable surface for asserting the encoded `t` value.
- Self-healing across transitions while the page stays open: the refresh response carries an `X-Display-State` header; on mismatch with the state baked into the page, the Stimulus controller does `window.location.reload()`. Handles pre→live (start of event) and live→post (end of event) without operator intervention.

### Out of scope

- Grace margins around the window (organisers configure padding into the event window itself).
- Behaviour when `startsAt`/`endsAt` are null. They can't be, post-#45.
- Showing a countdown to `startsAt` on the pre-event screen.
- Tightening the transition lag below ~60 s.
- Gating the public landing page (`/e/{slug}`) — separate concern, not requested.
- JS unit tests for the Stimulus controller (no JS test setup exists yet; transition behaviour is exercised indirectly via header roundtrip in functional tests).

## Architecture

Three units of new/changed code; no migration, no new routes.

### `App\Entity\EventDisplayState` (new)

PHP enum, string-backed. Lives next to `Event` in `src/Entity/`, matching the convention set by `PhotoStatus` and `InvitationStatus`.

```php
enum EventDisplayState: string
{
    case Pre  = 'pre';
    case Live = 'live';
    case Post = 'post';
}
```

String backing is deliberate — the value serialises directly into the `X-Display-State` HTTP header and the `data-qr-refresh-state-value` Stimulus attribute without ad-hoc mapping.

### `Event::computeDisplayState(DateTimeImmutable $now): EventDisplayState` (new)

Pure method on the entity, no DI:

```php
public function computeDisplayState(DateTimeImmutable $now): EventDisplayState
{
    if ($now < $this->startsAt) {
        return EventDisplayState::Pre;
    }

    if ($now > $this->endsAt) {
        return EventDisplayState::Post;
    }

    return EventDisplayState::Live;
}
```

`DateTimeImmutable` comparison is timezone-aware, so callers don't need to pre-rebase `$now`. Boundary inclusivity falls out of the `<` / `>` choice: `$now == startsAt` and `$now == endsAt` both land in Live, matching the AC.

### `Public\EventController::display()` (modified)

```php
$event = $this->resolve($slug);
$now   = $this->nowInEventTimezone($event);
$state = $event->computeDisplayState($now);

$photosUrl = match ($state) {
    EventDisplayState::Pre  => $this->photosUrl->build($event, $event->getStartsAt()->setTimezone(new DateTimeZone($event->getTimezone())), absolute: true),
    EventDisplayState::Live => $this->photosUrl->build($event, $now, absolute: true),
    EventDisplayState::Post => null,
};

$qrSvg = $state === EventDisplayState::Post
    ? null
    : $this->qr->svg($photosUrl, $this->readLogoBytes($event), size: self::DISPLAY_QR_SIZE);

return $this->render('public/event/display.html.twig', [
    'event'     => $event,
    'now'       => $now,
    'state'     => $state,
    'photosUrl' => $photosUrl,
    'qrSvg'     => $qrSvg,
]);
```

The pre-event anchor is `startsAt` rebased to event TZ so `PhotosUrlBuilder` (which calls `$when->format('H:i')`) emits the correct local `HH:mm`. Same pattern `nowInEventTimezone()` uses for `now`.

### `Public\EventController::displayQr()` (modified)

Same state computation. Branches on response shape:

| State | Status | Body | Headers |
|---|---|---|---|
| Pre | 200 | SVG (anchored to `startsAt`) | `Content-Type: image/svg+xml`, `Cache-Control: no-store`, `X-Display-State: pre`, `X-Photos-Url: <absolute url>` |
| Live | 200 | SVG (anchored to `now`) | same as Pre with `X-Display-State: live` |
| Post | 204 | (empty) | `X-Display-State: post` |

`X-Display-State` is on every response so the client can detect transitions without parsing the body. `X-Photos-Url` is omitted for Post (no URL applies).

To avoid duplicating state-resolution in `display()` and `displayQr()`, extract a small private helper:

```php
/** @return array{0: DateTimeImmutable, 1: EventDisplayState} */
private function resolveNowAndState(Event $event): array
{
    $now = $this->nowInEventTimezone($event);
    return [$now, $event->computeDisplayState($now)];
}
```

### `templates/public/event/display.html.twig` (modified)

Single template, three branches. Shared chrome (event name, layout container).

```twig
{# Wrapper: Stimulus controller wired for Pre and Live only. Post is terminal. #}
<main
    {% if state.value != 'post' %}
    data-controller="qr-refresh"
    data-qr-refresh-endpoint-value="{{ path('public_event_display_qr', {slug: event.slug}) }}"
    data-qr-refresh-timezone-value="{{ event.timezone }}"
    data-qr-refresh-state-value="{{ state.value }}"
    data-qr-refresh-interval-ms-value="60000"
    {% endif %}
    class="..."
>
    <h1>{{ event.name }}</h1>

    {% if state.value == 'post' %}
        <p class="text-2xl">This event has ended.</p>
    {% else %}
        <div data-qr-refresh-target="qr">{{ qrSvg|raw }}</div>
        <p>
            {% if state.value == 'pre' %}
                Starts <time>{{ event.startsAt|date('H:i', event.timezone) }}</time>
            {% else %}
                Updated <time data-qr-refresh-target="updated"
                    datetime="{{ now|date('c') }}">{{ now|date('H:i', event.timezone) }}</time>
            {% endif %}
            ·
            <a data-qr-refresh-target="photosUrl" href="{{ photosUrl }}">{{ photosUrl }}</a>
        </p>
    {% endif %}
</main>
```

The visible URL is identical to the link's `href`, so functional tests assert one source of truth and humans get a readable, copyable string under the QR.

### `assets/controllers/qr_refresh_controller.js` (modified)

Adds one new `static value` (`state: String`) and one new target (`photosUrl`). Behaviour changes:

- `connect()`: if `stateValue === 'post'` do not start polling. Defensive — the template already omits the controller in Post state.
- `refresh()`:
  - Read `response.headers.get('X-Display-State')`. If it differs from `stateValue`, call `window.location.reload()` and return. Don't apply partial updates from a different-state response.
  - Otherwise: existing SVG swap + timestamp update, plus a new step to set `photosUrlTarget.href` and `photosUrlTarget.textContent` from `response.headers.get('X-Photos-Url')` when present and the target exists.

## Data flow

**Initial page load** — controller resolves event → computes `now` (event TZ) → computes `state` → branches anchor (`startsAt` for Pre, `now` for Live, none for Post) → renders state-specific template branch. Response carries the state baked into `data-qr-refresh-state-value` (or omits the controller entirely for Post).

**Poll tick (Pre or Live)** — Stimulus fires every 60 s → `GET /e/{slug}/display/qr.svg` → controller re-resolves state →

- Same state → returns SVG with `X-Display-State` matching the page; client swaps the QR, the URL link, and the local timestamp. In Pre state the URL header is the same on every tick (no visible change), which is fine.
- Different state → returns either a new-state SVG (Pre↔Live) or 204 (anything→Post); client sees `X-Display-State` mismatch and reloads. Server then renders the new state's layout on the next request.

**Transition timing** — worst-case lag between the actual `startsAt`/`endsAt` boundary and the UI catching up is one tick (~60 s). Spec doesn't require tighter.

## Error handling

- **Unknown slug** on either route → existing `resolve()` throws `NotFoundHttpException` (404). Unchanged.
- **Refresh fetch fails** (network/5xx) — existing client behaviour: silent, keep the current QR, retry next tick. Unchanged.
- **Logo read fails** → existing `readLogoBytes()` logs warning and returns `null`; QR rendered without logo. Unchanged.
- **`startsAt > endsAt`** — shouldn't be reachable after #45. `computeDisplayState` returns Pre or Post but never Live; no explicit guard.
- **Server/browser clock skew** — irrelevant; server is authoritative, browser reloads on header mismatch.
- **Stimulus / template state drift** — defended by the `stateValue === 'post' → no polling` guard in `connect()`.

## Testing

### Unit — extend `tests/Unit/Entity/EventTest.php`

- `computeDisplayState` returns Pre for `now < startsAt`.
- Returns Live for `now == startsAt` (boundary inclusive).
- Returns Live for `now` strictly inside the window.
- Returns Live for `now == endsAt` (boundary inclusive).
- Returns Post for `now > endsAt`.
- One DST/cross-day check: event `startsAt = 2026-10-25 02:30 Europe/Amsterdam` (just before the autumn fall-back). Verify the `DateTimeImmutable` comparison still classifies `now` correctly — this isn't asserting any TZ conversion in the entity (there is none), it's a regression-pin against accidentally formatting + comparing strings later.

### Functional — extend `tests/Functional/Public/EventDisplayTest.php`

Tests for all three states:

- `testDisplayPageInPreEventStateRendersStaticQrAnchoredToStartsAt` — event window in the future; assert page contains `Starts HH:mm`, an `<a href>` whose URL ends with `?t=<startsAt-HH:mm>` and matching link text, and `data-qr-refresh-state-value="pre"`.
- `testDisplayPageInLiveStateRendersTimestampedQr` — clock inside window (current `testDisplayPageRendersQrEncodingPhotosUrlInCurrentFormat` updated): assert `data-qr-refresh-state-value="live"` plus existing assertions.
- `testDisplayPageInPostEventStateHasNoQrAndShowsEndedMessage` — event window in the past; assert no `<svg`, no `data-controller="qr-refresh"`, body contains "This event has ended."
- `testDisplayPageBoundaryAtStartsAtIsLive` — clock == `startsAt` exactly; live assertions.
- `testDisplayPageBoundaryAtEndsAtIsLive` — clock == `endsAt` exactly; live assertions.

Tests for the refresh endpoint:

- `testRefreshEndpointInPreStateReturnsSvgAndStateHeaders` — assert 200, `X-Display-State: pre`, `X-Photos-Url` ends with `?t=<startsAt-HH:mm>`, body contains `<svg`.
- `testRefreshEndpointInLiveStateReturnsSvgAndLiveHeader` — existing test extended with header assertions.
- `testRefreshEndpointInPostStateReturns204WithStateHeader` — assert 204, `X-Display-State: post`, empty body, no `X-Photos-Url`.

### Clock control for tests

Functional tests need deterministic `now` for boundary assertions. The codebase does not yet wire a `MockClock` in the test environment.

Approach: in test env, alias `Symfony\Component\Clock\ClockInterface` to a `MockClock` service registered with a deterministic constructor time. Tests retrieve it via `$container->get(ClockInterface::class)` (instance check to `MockClock`) and call `->modify('@<unix-ts>')` or `->sleep()` to set time per case. This is the standard Symfony pattern and adds one block to `config/services.yaml` under a `when@test` guard.

If wiring MockClock turns out to fight with the existing tests (which assume real clock), the fallback is to construct event windows around real `now` for Pre/Post cases and skip the exact-boundary tests, accepting some loss of strictness. The wiring approach is preferred and tried first.

### Implementation order suggested

1. Add `EventDisplayState` enum + `Event::computeDisplayState` + unit tests. Green.
2. Wire `MockClock` in test env; rewrite existing display tests to use it. Green.
3. Modify `display()` and `displayQr()` controllers + template branching. Functional tests for Pre, Live, Post initial render. Green.
4. Add `X-Display-State` / `X-Photos-Url` headers; functional tests for refresh endpoint per state. Green.
5. Modify `qr_refresh_controller.js` (new value, new target, mismatch-reload, URL-swap). Manual smoke test in browser; no JS unit test.

## Acceptance criteria (mapped from the issue)

- [x] *Pre: `/display` renders a QR encoding `/e/{slug}/photos?t=<startsAt>` (event TZ).* — `display()` Pre branch, anchor = `startsAt` rebased to event TZ.
- [x] *Pre: QR does not refresh client-side.* — Pre Stimulus poll returns same SVG on every tick; visually static. (Polling exists for transition detection.)
- [x] *Live: behaviour unchanged from #37.* — Live branch preserves existing controller + template + Stimulus contract; only adds optional header surface that older flows ignore.
- [x] *Post: no QR; "ended" message in same layout.* — Post template branch; no `<svg`, no Stimulus controller wired.
- [x] *Boundary `now == startsAt` and `now == endsAt` are Live.* — `<` / `>` comparisons in `computeDisplayState`.
- [x] *Functional tests cover all three states including the encoded `t` in pre and absence in post.* — Test list above; encoded `t` asserted via the link's `href` (visible string identical).
