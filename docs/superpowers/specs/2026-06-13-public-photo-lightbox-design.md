# Public Photo Lightbox — Design

## Problem

On the public photo grid (`/e/{slug}/photos`), clicking a thumbnail opens the full preview in a new browser tab. That breaks the browsing flow: every photo is a context switch, the user loses their place in the grid, and there's no fluid way to scan through photos sequentially. At an event — primarily phone-viewed — this is friction in exactly the moment people want to flip through what's been captured.

## Goal

Replace the new-tab open with an in-page lightbox that overlays the current page, supports keyboard, mouse, and touch navigation between photos, preloads adjacent photos so navigation is instant, and gracefully shows a loading state when the user out-runs the preload. Each open photo gets a shareable URL via a hash fragment.

## Non-goals

- Zoom / pinch-to-zoom inside the lightbox
- Pre-fetching more than the immediate next/previous photo
- Slideshow / auto-advance
- Download or share-photo buttons inside the lightbox
- Captions, EXIF data, or photo metadata display
- Cross-window/time-filter navigation (jumping to photos outside the currently-loaded window)

## User flow

1. User lands on `/e/{slug}/photos` (or follows a shared `…/photos#p=<id>` link).
2. Clicks a thumbnail → lightbox opens overlaying the page; URL gains `#p=<photoId>`; browser pushes a history entry so the back button closes the lightbox.
3. Navigates next/previous via:
   - Click arrow buttons overlaid on the left/right sides of the image
   - Keyboard ← / → keys
   - Horizontal touch swipe
4. Each navigation swaps the image inline (no page reload) and **replaces** the URL hash (so the back button still exits the lightbox in one step rather than retracing photo-by-photo).
5. Adjacent photos (active ± 1) are preloaded in the background. If the user navigates faster than preload, the new `<img>` shows blank momentarily; after 200ms a spinner overlays it; on `load` the spinner disappears.
6. Closes via Esc key, X button in the corner, click on the backdrop, or browser back button. URL hash is cleared.

## Architecture

A single Stimulus controller, `assets/controllers/lightbox_controller.js`, mounted on the photo grid `<section>`. The controller owns:

- An in-memory array of `{id, previewUrl}` derived from the rendered grid via `data-*` attributes on each tile (no JS data island).
- An `activeIndex` (or `null` when closed).
- A `preloadState` map: `Map<id, 'idle' | 'loading' | 'loaded' | 'error'>`.

The lightbox UI is a native `<dialog>` element appended inside the controller scope. We use `<dialog>` over a styled div because it provides:

- Native focus trapping while open
- Native Esc-to-close
- Correct ARIA semantics out of the box
- Plays cleanly with Turbo navigation (the element is regular DOM, no portal)

Styling uses daisyUI `modal` classes plus Tailwind for the chrome (close button, arrows, spinner, counter). No new third-party JS.

### Why DIY over a library

PhotoSwipe / lightGallery bring ~30 KB gzipped, opinionated styling that fights daisyUI, and features (zoom, captions, gallery thumbnails) we don't want. The custom build is ~150 lines and follows the conventions in `photos_poller_controller.js` and `photo_uploader_controller.js`.

## Twig changes

`templates/public/event/photos.html.twig`:

- Wrap the existing `<section>` (or just the grid `<ul>`) with the controller declaration: `data-controller="lightbox"`.
- Each `<li>` carries:
  - `data-lightbox-target="trigger"`
  - `data-photo-id="{{ photo.id }}"`
  - `data-preview-url="{{ path('photo_serve_preview', {slug: event.slug, id: photo.id}) }}"`
- The `<a>` keeps its `href` (so right-click → "open in new tab" and no-JS users still get the original behavior), but loses `target="_blank"`. The controller calls `preventDefault()` on click.
- Append the dialog markup at the end of the section:

```html
<dialog data-lightbox-target="dialog" class="modal">
    <div class="modal-box max-w-full w-full h-full p-0 bg-black/95 ...">
        <button data-action="click->lightbox#close" class="...">×</button>
        <button data-lightbox-target="prevButton" data-action="click->lightbox#previous" class="...">‹</button>
        <img data-lightbox-target="image" alt="" class="..." />
        <div data-lightbox-target="spinner" class="hidden ...">…</div>
        <button data-lightbox-target="nextButton" data-action="click->lightbox#next" class="...">›</button>
        <div data-lightbox-target="counter" class="..."></div>
    </div>
    <form method="dialog" class="modal-backdrop"><button>close</button></form>
</dialog>
```

(Exact classes finalized during implementation; the structure is what matters here.)

## Controller behavior

### `connect()`

1. Collect triggers into the photo list (preserving DOM order).
2. Bind `click` on the grid (event delegation) for triggers.
3. Bind `keydown` on the dialog for ← / → / Esc.
4. Bind `popstate` on window.
5. Read `location.hash` — if `#p=<id>` matches a known photo, open the lightbox at that index without pushing a new history entry (replaceState to normalize).

### `open(index, { fromHistory = false })`

1. Set `activeIndex = index`.
2. Render: set `<img>.src` to the active photo's preview URL.
3. Show dialog: `dialog.showModal()`.
4. Update counter ("3 / 24") and arrow visibility (hide prev at index 0, next at index length-1).
5. If `!fromHistory`: `history.pushState({lightbox: true, id}, '', '#p=' + id)`.
6. Kick off preload for index ± 1.

### `next()` / `previous()` / `goTo(index)`

`next()` and `previous()` are thin wrappers around `goTo(activeIndex ± 1)` that also bail at boundaries. `goTo(index)` is also called by `popstate` when the active hash changes while the lightbox is already open.

1. Bail if `index === activeIndex` or out of bounds.
2. Set `activeIndex = index`.
3. Swap `<img>.src` to new photo. If `preloadState.get(id) === 'loaded'`, the swap is instant. Otherwise:
   - Show spinner after 200ms timeout
   - On `<img>`'s `load` event: clear the timeout / hide spinner
   - On `error`: hide spinner, show inline "Couldn't load this photo" message; arrows remain functional
4. `history.replaceState({lightbox: true, id: newId}, '', '#p=' + newId)`.
5. Update counter and arrow visibility.
6. Kick off preload for the new index ± 1.

### `close({ fromHistory = false })`

1. `dialog.close()`.
2. `activeIndex = null`.
3. If `!fromHistory`: `history.back()` if our state is on the stack; else `history.replaceState({}, '', location.pathname + location.search)` to drop the hash.

### Preload

```js
preload(index) {
    if (index < 0 || index >= photos.length) return;
    const { id, previewUrl } = photos[index];
    const state = this.preloadState.get(id);
    if (state === 'loading' || state === 'loaded') return;
    this.preloadState.set(id, 'loading');
    const img = new Image();
    img.onload  = () => this.preloadState.set(id, 'loaded');
    img.onerror = () => this.preloadState.set(id, 'error');
    img.src = previewUrl;
}
```

Browser image cache holds the result; subsequent `<img>.src = previewUrl` resolves instantly.

### Touch swipe

Use Pointer Events (well-supported, unified mouse/touch/pen):

- `pointerdown` on the image element: record `startX`, `startY`, `pointerId`.
- `pointermove`: track latest position; if `|dy| > |dx|` and `|dy| > 10px`, abandon (user is scrolling vertically — release the gesture).
- `pointerup`: if horizontal `|dx| ≥ 50px`, trigger `next()` or `previous()` based on direction.
- `pointercancel`: reset.

Mouse drags also trigger this, which is acceptable — dragging a fullscreen image with the mouse to flip is a reasonable extra affordance.

### History (popstate)

```js
onPopState(event) {
    const hash = location.hash.match(/^#p=(\d+)$/);
    if (hash) {
        const id = Number(hash[1]);
        const index = this.indexOfPhoto(id);
        if (index < 0) { this.close({ fromHistory: true }); return; }
        if (this.activeIndex === null) this.open(index, { fromHistory: true });
        else if (this.activeIndex !== index) this.goTo(index, { fromHistory: true });
    } else if (this.activeIndex !== null) {
        this.close({ fromHistory: true });
    }
}
```

### Deep-link with stale hash

If `location.hash` is `#p=<id>` for a photo not in the current grid (e.g., user reloaded with a different `?t=` time filter), the controller ignores the hash and replaces it with the clean URL. No fetch attempt.

## Accessibility

- `<dialog>` provides focus trap and Esc semantics automatically.
- Arrow buttons get `aria-label="Previous photo"` / `aria-label="Next photo"`.
- Close button gets `aria-label="Close"`.
- The image `<img>` carries `alt="{{ event.name }}"` matching the thumbnail.
- Counter is an `aria-live="polite"` region announcing "3 of 24" on change.
- Hidden boundary arrows use `hidden` attribute (removed from tab order), not just `display: none` via class — though daisyUI's hidden class does the same, the explicit attribute is more predictable for screen readers.

## Error & edge cases

| Case | Behavior |
|---|---|
| Preview image 404s | Inline "Couldn't load this photo" message replaces image; arrows still work; preload state marked `error` |
| Hash references unknown photo id | Ignore + clear hash to canonical URL |
| Photo grid is empty | Lightbox is never instantiated; controller targets `triggers.length === 0` so `connect()` is a no-op |
| Single photo in grid | Both arrows hidden, swipe is no-op, keyboard ← / → are no-ops |
| User reloads page while lightbox is open | Hash preserves state; controller re-opens at the same photo on `connect()` |
| Browser doesn't support `<dialog>` | We rely on it; all modern evergreen browsers (Chrome ≥37, Firefox ≥98, Safari ≥15.4) support it. No polyfill |
| Pointer-event gesture interrupted by browser (e.g., back-gesture from edge) | `pointercancel` resets state; image stays at current photo |
| User clicks a thumb while lightbox already open (shouldn't happen — grid is behind backdrop) | Defensive: `open()` checks `dialog.open` and re-routes to `swapTo()` |

## Testing

**Functional (PHPUnit, server-rendered DOM only — Panther is not wired in this project):**
- Photo grid page renders the dialog element and required targets.
- Each `<li>` carries `data-photo-id` and `data-preview-url`.
- The `<a>` still has its `href` (graceful degradation).

**JS behavior (manual verify via `/verify` skill against the running app, since PHPUnit can't exercise the Stimulus controller):**
- Click thumb → lightbox opens; URL updates to `#p=<id>`.
- ← / → keys cycle; URL hash replaces (no back-button spam).
- Boundary arrows are hidden at first / last.
- Esc / X / backdrop / browser-back all close cleanly and clear the hash.
- Preload: rapid-fire next clicks show the spinner only when actually waiting.
- Touch swipe (real device or Chrome devtools touch emulation).
- Reload with `#p=<id>` in URL opens the lightbox at that photo.
- Reload with `#p=<bogus-id>` ignores and cleans the URL.
- `?t=08:30#p=<id>` where the id is out of window → ignore + clean.

**Verify script (`bin/console` invocations or just `vendor/bin/phpunit`):** standard suite passes; no new PHP code paths beyond the Twig change.

## Files touched

| File | Change |
|---|---|
| `assets/controllers/lightbox_controller.js` | New (~150 lines) |
| `assets/controllers.json` | Register if Stimulus auto-discovery doesn't pick it up |
| `templates/public/event/photos.html.twig` | Add controller scope, data attributes, dialog markup; remove `target="_blank"` |
| `tests/Functional/Public/EventPhotosPageTest.php` (or existing equivalent) | DOM assertions for new markup |

## Out of scope (recap for future tickets)

- Zoom / pinch
- Preload depth beyond ±1
- Slideshow auto-advance
- Download / share buttons in lightbox
- Captions, EXIF, metadata
- Cross-window navigation
- URL hash for jumping to a specific time filter's photos
