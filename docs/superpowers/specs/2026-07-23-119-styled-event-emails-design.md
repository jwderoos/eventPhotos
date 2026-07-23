# Styled event emails with inline hero — design (#119)

Builds on the event-styling foundation (#54 `StyleResolver` / `ResolvedStyle` / `StyleSettings`),
the event banner/hero (#93 `event_banners_storage`, `Event::bannerFilename`), and the visitor
email-notification flow (#77 `EventNotificationSubscription`, organizer-transport sends). The
two event-facing emails are currently plain, unbranded sans-serif HTML; this makes them adopt
the event's resolved colors and show the event hero image inline.

## Goal

Make the two event-context emails visually consistent with the event's public styling, and
include the event hero (banner) image when one is set — rendered reliably even in clients that
block remote images.

## Scope

- **In scope:** the two event-context emails only:
  - `email/event-notification/confirm` — double-opt-in confirmation
    (`Public\EventNotificationController::…`, currently at `EventNotificationController.php:187`).
  - `email/event-notification/live` — "photos are live"
    (`MessageHandler\SendEventLiveEmailHandler`, currently at `SendEventLiveEmailHandler.php:63`).
- **Out of scope:** platform emails with no event context — invitations, password reset,
  `email/mail-config/verify`. They have no event to inherit a style/banner from and keep their
  current layout.

## Decisions (locked during brainstorming)

- **Event-context emails only.** These two are the only sends tied to a specific `Event`, so the
  only ones with a style/banner to inherit. Platform emails are explicitly excluded.
- **Hero via inline CID embed — not a remote URL.** The banner is attached to the message as an
  inline `DataPart` and referenced by content-id in the template. Inline embedding renders in
  clients that block remote images by default (Gmail/Outlook), unlike referencing the public
  `public_event_banner` URL. Accepted trade-off: slightly larger emails.
- **Reuse existing style + banner infrastructure.** No new style settings, no new columns. Colors
  come from `StyleResolver::resolve($event)`; the hero comes from the existing
  `event_banners_storage` derivative. `glowEnabled` is intentionally dropped for email (a screen
  glow effect does not translate to email HTML).
- **One shared builder.** A single `EventStyledEmailFactory` owns style-resolve + banner-attach so
  the logic is not duplicated across the two send sites (also avoids the `phpcpd` 50-line /
  100-token gate). Each call site keeps owning its recipient, subject, from, and context keys.

## Architecture

### `Service\Mail\EventStyledEmailFactory` (new)

The one place that turns an event + template into a styled, hero-embedded `TemplatedEmail`.

```
create(
    Event $event,
    string $htmlTemplate,
    string $textTemplate,
    array $context,       // caller's keys: eventName, eventUrl, unsubscribeUrl, confirmUrl, …
): TemplatedEmail
```

Responsibilities:

- Resolve style: `$style = $this->styleResolver->resolve($event)` (the existing event →
  collection → organizer-profile cascade).
- Merge a `style` context key exposing the resolved tokens the layout needs
  (`fontColor`, `backgroundColor`, `buttonColor`, `buttonContentColor()`), each nullable — the
  layout applies neutral defaults when null.
- Attach the hero when `$event->getBannerFilename()` is non-null: read bytes from
  `event_banners_storage`, add an inline `DataPart` (JPEG) with a stable content-id, and expose a
  `heroCid` context flag/value so the layout renders the `<img src="cid:…">`. On a storage
  read/decode failure, **catch, log, and continue** — the email still sends without the hero
  (a missing hero must never fail a notification send).
- Return the `TemplatedEmail` with `htmlTemplate`/`textTemplate`/`context` set. The caller adds
  `->from()`/`->to()`/`->subject()` and sends via its existing mailer
  (`OrganizerMailerResolver::forEvent($event)`).

Dependencies (constructor-injected): `StyleResolver`, and the banner storage via
`#[Autowire(service: 'event_banners_storage')] FilesystemOperator $banners` (six
`FilesystemOperator` services exist — plain autowiring is ambiguous), plus a `LoggerInterface`.

Rendering note: sends flow through `RenderingMailer`, which calls `BodyRenderer::render()` on the
`TemplatedEmail` — this both renders the Twig body and processes inline image parts, so the CID
hero resolves on the exact path these emails already use. No transport changes.

### Email-safe layout — `templates/email/_layout.html.twig` (new)

A reusable table-based, inline-styled email layout. Email clients strip `<style>`/external CSS and
ignore CSS custom properties, so the public `_base.html.twig` (CSS-variable) approach cannot be
reused — colors are applied as inline `style` attributes.

- Outer full-width table; centered content table (~600px) as the email-client convention.
- Applies resolved colors inline: page/background from `style.backgroundColor`, body text from
  `style.fontColor`, and a themed CTA button (`background: style.buttonColor; color:
  style.buttonContentColor`). Each falls back to a tasteful neutral default when its token is null
  (defined once as Twig defaults in the layout).
- **Hero block** at the top, rendered only when `heroCid` is set:
  `<img src="cid:{{ heroCid }}" style="width:100%; max-width:600px; height:auto; display:block">`
  with the event name as `alt`. When absent, the header degrades to the event name (no broken
  image).
- Exposes blocks the two templates fill (`title`, `body`/`cta`, and the shared unsubscribe
  footer). The two HTML templates become `{% embed 'email/_layout.html.twig' %}` bodies.
- `.txt.twig` variants are unchanged — plain text has no styling/hero to apply.

### Call-site changes

Both sites drop their hand-built `new TemplatedEmail()` chain and instead:

```php
$email = $this->styledEmailFactory->create($event, $htmlTemplate, $textTemplate, $context);
$mailer->send($email->from(...)->to(...)->subject(...));
```

- `SendEventLiveEmailHandler` — keeps its `mailerResolver->forEvent($event)`, URL generation, and
  `markNotified`/flush; only the email construction is delegated.
- `EventNotificationController` (confirm send) — same delegation; keeps its own `confirmUrl`
  context and subject.

## Testing

- **Unit — `EventStyledEmailFactory`:**
  - Merges resolved colors into the `style` context key; passes through caller context.
  - Attaches an inline image part and sets `heroCid` when the event has a banner.
  - No hero part and no `heroCid` when `bannerFilename` is null.
  - A storage read failure is swallowed (logged) and the returned email still has no hero — send
    is not aborted.
- **Functional (over the `when@test` in-memory transport):**
  - Live + confirm emails render with the resolved font/background/button colors inline in the
    HTML body.
  - When the event has a banner, the sent message carries an inline image part and the HTML
    references its CID; when it does not, neither is present and the header shows the event name.
  - `.txt` alternative part is still present and unaffected.
- **Regression:** existing #77 notification tests (subscription state, unsubscribe token,
  organizer-transport send, no platform fallback) still pass.
- All GrumPHP gates green: phpstan L10, phpcs (PSR-12), phpmnd (no magic numbers in `src/`),
  phpcpd, rector, `doctrine:schema:validate`.

## Out of scope (follow-ups if ever wanted)

- Styling platform (non-event) emails — invitations, password reset, mail-config verify.
- Any new organizer-configurable, email-specific style settings (reuse existing `StyleSettings`).
- Email dark-mode handling / `prefers-color-scheme` media queries.
- Showing the event logo (as opposed to the banner) in the email header.
- Applying the `glowEnabled` effect in email.
