# Rate limiting on public event routes (#23)

**Status:** Approved design — ready for implementation plan
**Date:** 2026-06-20
**Issue:** #23

## Goal

Protect the anonymous public event routes against **slug enumeration** and a
**generic request flood / DoS backstop**, using a coarse per-client-IP rate
limit. This is *not* anti-scraping: bulk photo download via the image routes is
explicitly out of scope (those are high-volume legit traffic).

## Threat model (decided)

In scope:

- **Slug enumeration** — an attacker spraying random `/e/{slug}` values to
  discover which events exist. Cheap requests, high volume, hits the 404 path
  in `EventController::resolve()`.
- **Generic flood / DoS** — volumetric hammering of the public HTML/JSON event
  routes to exhaust php-fpm / DB. A coarse per-IP cap is the backstop.

Out of scope:

- **Bulk photo scraping** — downloading every photo via
  `/e/{slug}/p/{id}/thumb.jpg|preview.jpg`. These look like legit gallery
  browsing and are already cache-friendly (1-year immutable + ETag). Not
  limited.
- **Kiosk/poll abuse** of the display routes beyond what the generic cap
  already covers.
- **Invitation-token brute force** (`/invitation/*` redemption) — a different
  limiter design (per-token / tighter), tracked separately, not part of #23.

## Scope — routes covered

Rate-limited, sharing **one per-client-IP bucket**:

| Route name | Path | Why |
|---|---|---|
| `public_event_landing` | `/e/{slug}` | enumeration entry point + flood |
| `public_event_photos` | `/e/{slug}/photos` | flood (also reachable via enumeration redirect) |
| `public_event_display` | `/e/{slug}/display` | flood backstop (kiosk) |
| `public_event_display_qr` | `/e/{slug}/display/qr.svg` | flood backstop (polled SVG) |

Explicitly **excluded**:

| Route name | Path | Why excluded |
|---|---|---|
| `photo_serve_thumb` | `/e/{slug}/p/{id}/thumb.jpg` | high-volume legit (~200 requests / page load); rate-limiting breaks galleries; scraping out of scope |
| `photo_serve_preview` | `/e/{slug}/p/{id}/preview.jpg` | same |
| `public_event_photos_neighbor` | `/e/{slug}/photos/{id}/neighbor` | lightbox JSON nav; needs a valid slug + Ready photo id (not an enumeration vector); with #81 preload, a fast lightbox session could fire several requests per swipe and false-trip the cap |
| home / setup / invitation redemption | — | not event routes / different concern |

**Matching is by route name, not path prefix.** Photo-serve lives under
`/e/{slug}/p/...`, so a path-prefix match on `/e/` would wrongly catch it. The
listener matches `_route` against an explicit allowlist of the four
`public_event_*` names above.

## Limit shape (decided)

- Policy: **sliding window**
- Limit: **120 requests / minute** per client IP
- On exceed: **HTTP 429** with a `Retry-After` header

~2 req/sec sustained — generous for a human clicking through a gallery, brutal
for a slug-sprayer or flooder.

## Mechanism

### Limiter definition

New `config/packages/rate_limiter.yaml`:

```yaml
framework:
    rate_limiter:
        public_event:
            policy: 'sliding_window'
            limit: 120
            interval: '1 minute'
```

This auto-provides a `RateLimiterFactory` (Symfony names the autowire alias
`$publicEventLimiter` after the limiter key) injectable into the listener.

### Request listener

`App\EventListener\PublicRateLimitListener`, registered with
`#[AsEventListener(event: KernelEvents::REQUEST)]`:

- Priority **below the RouterListener's 32** (so `_route` is already populated)
  and above the controller. Use a class constant for the priority value to keep
  `phpmnd` clean.
- `isMainRequest()` guard — ignore sub-requests / ESI fragments.
- Read `$request->attributes->get('_route')`; return early if it is not one of
  the four allowlisted route names.
- Key the limiter on `$request->getClientIp()`. This respects the prod
  `trusted_proxies` config already in `framework.yaml` (`when@prod`), and falls
  back to direct `REMOTE_ADDR` in dev/test. If `getClientIp()` returns `null`
  (should not happen for real HTTP), skip limiting defensively.
- `consume(1)`. If `!isAccepted()`, throw
  `TooManyRequestsHttpException(retryAfter: …)` derived from the returned
  `RateLimit::getRetryAfter()` — Symfony renders **429** and sets `Retry-After`.

The token is consumed **before the controller runs**, so requests to
non-existent slugs (which 404 in the controller) still count. That is what
makes the limiter bite slug enumeration.

### Storage

Default app cache pool (filesystem). Fine on the single-host TrueNAS deploy and
shared across php-fpm workers via the filesystem. No distributed lock is
required — worst case under concurrency is a negligible over-count (a few extra
requests admitted), acceptable for a coarse backstop.

Limiter state is **not** session-coupled, so this does not disturb the #68
invariant that anonymous public routes do not touch the session / create
`sessions` rows.

## Tests (`tests/Functional/`)

Per-test isolation via a distinct `REMOTE_ADDR` server param per test method
(no trusted proxy in the test env → client IP = `REMOTE_ADDR`), so limiter
buckets do not bleed across tests. Filesystem cache survives the test client's
kernel reboots between requests, so the counter accumulates correctly within a
test.

- **Threshold:** 120 requests to `/e/{slug}` (valid event) all pass; the 121st
  returns 429 with a `Retry-After` header.
- **Enumeration:** 120 requests to a **non-existent** slug return 404 but still
  accumulate; the 121st returns 429 — proving the limiter runs before the
  controller.
- **Excluded image route:** 200+ requests to `photo_serve_thumb` never return
  429.
- **Excluded nav route:** rapid `public_event_photos_neighbor` requests are not
  limited.

## Risks / notes

- **phpmnd:** the `120` / `1 minute` values live in YAML, not `src/`, so no
  magic-number gate. Any integer literal in the listener (e.g. the listener
  priority) goes in a class constant to stay clean.
- **Kiosk polling:** legit `display-qr` polling is ~1/min — three orders of
  magnitude under the cap.
- **Shared-IP false positives:** corporate NAT or a packed venue behind one
  wifi egress could aggregate behind a single IP. 120/min/IP is generous for
  individual humans; if real traffic trips it, loosen to 300/min — a
  config-only change in `rate_limiter.yaml`.

## Acceptance criteria

- [ ] `config/packages/rate_limiter.yaml` defines the `public_event`
      sliding-window limiter (120 / 1 minute).
- [ ] `PublicRateLimitListener` limits the four allowlisted `public_event_*`
      routes per client IP and returns 429 + `Retry-After` on exceed.
- [ ] Photo-serve and neighbor routes are not limited.
- [ ] Functional tests cover threshold, enumeration (404s count), and the two
      exclusions.
- [ ] PHPStan level 10 clean, `phpcs` PSR-12, `phpmnd` clean,
      `doctrine:schema:validate` green (no schema change expected).
