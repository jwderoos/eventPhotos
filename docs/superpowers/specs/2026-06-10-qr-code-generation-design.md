# QR code generation in the admin — design

**Goal:** Let an organizer open a print-friendly page for an event that shows a QR code pointing at the public landing URL, so they can print it and put it at the event.

**Scope:** Admin-only feature. Public site is unchanged. No new entity, no new migration.

---

## Decisions

| Question | Answer |
|---|---|
| Where does the QR live? | Dedicated print route only. No inline preview on edit form. |
| What's on the print page? | QR + event name + date + tagline "Scan to see your photos" + URL printed underneath in small text. |
| Image format on screen | Inline SVG (no second HTTP roundtrip, scales for print). |
| Download option | A "Download PNG" button. SVG-only is sufficient for browser printing; PNG covers organizers who paste into Word/Canva. |
| Navigation | "QR" action button in the admin events index table only. Opens in a new tab. |
| Library | `endroid/qr-code ^6`. SVG writer needs no PHP extensions; PNG writer uses GD/Imagick (already in the php-fpm image). |
| Access | Same ownership rule as edit (`admin OR owner`). 403 otherwise. |

---

## Architecture

### Routes

Two routes added to the existing `App\Controller\Admin\EventController`:

| Method | Path | Name | Returns | Auth |
|---|---|---|---|---|
| GET | `/admin/events/{id}/qr` | `admin_event_qr` | HTML print page (inline SVG) | `EVENT_VIEW` voter |
| GET | `/admin/events/{id}/qr.png` | `admin_event_qr_png` | `image/png` binary | `EVENT_VIEW` voter |

Both routes use the `EVENT_VIEW` attribute on the existing `EventVoter`. The voter already supports it but no caller has been using it — this is the first.

### URL encoded by the QR

Absolute URL of the public landing page:
```
{absolute UrlGenerator->generate('public_event_landing', {slug: event.slug})}
```

Generated with `UrlGeneratorInterface::ABSOLUTE_URL`. Symfony already has `DEFAULT_URI=http://localhost` in `.env` for CLI URL generation; in dev the request context will override it with the actual host (`http://localhost:8080`). In production this will be the public hostname.

### Service: QrCodeRenderer

`src/Service/QrCodeRenderer.php` — thin wrapper around endroid so the controller stays library-agnostic and so the rest of the codebase has one place to change if we ever swap libraries.

```php
final class QrCodeRenderer
{
    public function svg(string $url, int $size = 320): string;
    public function png(string $url, int $size = 512): string;
}
```

- `svg()` returns the SVG document body (string, ready to inline in Twig with `|raw`).
- `png()` returns binary PNG (suitable for a `Response` with `Content-Type: image/png`).
- Both use endroid's defaults: medium error correction, no logo, no margin overrides. If we want logo-in-center later, we add it here.
- Auto-wired as a service like every other class in `src/`.

### Controller changes

Two new actions in `App\Controller\Admin\EventController` (existing file):

```php
#[Route('/admin/events/{id}/qr', name: 'admin_event_qr', methods: ['GET'], requirements: ['id' => '\d+'])]
public function qr(Event $event, QrCodeRenderer $renderer): Response { ... }

#[Route('/admin/events/{id}/qr.png', name: 'admin_event_qr_png', methods: ['GET'], requirements: ['id' => '\d+'])]
public function qrPng(Event $event, QrCodeRenderer $renderer): Response { ... }
```

Both:
1. `denyAccessUnlessGranted(EventVoter::VIEW, $event);`
2. Build the absolute public URL via `UrlGeneratorInterface`.
3. Call the renderer, return SVG-in-Twig or PNG-as-binary respectively.

### Template

`templates/admin/event/qr.html.twig` — **does not** extend `admin/_base.html.twig` (no admin nav, no menu, no flash bar). Standalone layout.

Sections (top → bottom, all centered):
- QR (inline SVG, ~320px wide)
- `<h1>` event name
- Date (e.g. `2026-07-15`)
- Tagline: *Scan to see your photos*
- Absolute URL in small muted text (typing fallback)
- Two buttons: `[Print]` (calls `window.print()`) and `[Download PNG]` (link to `admin_event_qr_png`, `download` attr set to `event-{slug}.png`)

A small `@media print { ... }` block hides the two buttons so the print output is just the QR + text. Tailwind + daisyUI used for layout/styling consistent with the rest of the admin.

### Index table action

`templates/admin/event/index.html.twig` — the actions cell currently has `Edit` and a delete form. Add a `QR` link before `Edit`:

```twig
<a href="{{ path('admin_event_qr', {id: event.id}) }}" target="_blank" class="btn btn-sm">QR</a>
```

`target="_blank"` so the organizer doesn't lose their place in the index.

---

## Testing

### Unit: `tests/Unit/Service/QrCodeRendererTest.php`

- `svg()` returns a string containing `<svg` and is non-empty.
- `png()` returns binary starting with the PNG magic bytes (`\x89PNG\r\n\x1a\n`).
- Sanity: different URLs produce different output (so we know the URL is actually being encoded).

### Functional: `tests/Functional/Admin/EventQrTest.php`

Two-user fixture (alice + bob, alice owns the event):

1. **Owner sees print page** — `GET /admin/events/{id}/qr` as alice → 200, response body contains the event name and `<svg`.
2. **Owner downloads PNG** — `GET /admin/events/{id}/qr.png` as alice → 200, `Content-Type: image/png`, body starts with PNG magic bytes.
3. **Non-owner gets 403** — `GET /admin/events/{id}/qr` as bob → 403.

---

## What's not in scope

- Logo or branding inside the QR (no logo asset exists yet; can be added later by extending `QrCodeRenderer`).
- Bulk-print page (one A4 per event with multiple QRs on a sheet).
- PDF download (browser "Print → Save as PDF" is the workaround).
- QR for `EventCollection` (only `Event` has a public URL right now).
- Custom message field on the print page (you accepted the simpler tagline option).

---

## Files added / changed

**Added:**
- `src/Service/QrCodeRenderer.php`
- `templates/admin/event/qr.html.twig`
- `tests/Unit/Service/QrCodeRendererTest.php`
- `tests/Functional/Admin/EventQrTest.php`

**Modified:**
- `src/Controller/Admin/EventController.php` (two new actions)
- `templates/admin/event/index.html.twig` (one new link in actions cell)
- `composer.json` / `composer.lock` (add `endroid/qr-code`)

**Unchanged but used:**
- `src/Security/Voter/EventVoter.php` (`VIEW` attribute already declared; first caller is here)
