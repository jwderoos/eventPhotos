# Mobile lightbox: carousel swipe transition

**Issue:** [#70](https://github.com/jwderoos/eventPhotos/issues/70)
**Date:** 2026-06-15

## Problem

In the public photo lightbox, swiping on a phone advances to the next/previous photo with an instant `src` swap. The image does not follow the finger, so the interaction feels like tap-to-advance rather than a real swipe. We want a true carousel transition: the current image translates with the touch, the neighbor (already preloaded by the existing controller) slides in from the swipe direction, and on release the controller either snaps to the neighbor or springs back.

## Scope

- **In:** Touch + pen pointer gestures inside the public lightbox. Velocity-aware commit. Boundary rubber-band when the list endpoint has not yielded a neighbor yet. Reduced-motion fallback. Smooth (animated) transition for keyboard arrow and on-screen prev/next buttons so the visual model is consistent.
- **Out:** Mouse-drag gestures. Pinch/zoom. Vertical close-on-drag-down. Changes to the grid, neighbor API, or preload strategy. New tests (the JS bundle has no test runner today).

## Decisions

| Topic | Choice |
| --- | --- |
| Pointer types that drag | `touch`, `pen`. Mouse keeps today's click/keyboard nav only. |
| Commit rule | Distance ≥ 25% of viewport width **OR** release velocity ≥ 0.5 px/ms in the drag direction. |
| Boundary behavior (no neighbor yet) | Rubber-band: drag is multiplied by 0.4 and the off-screen slot is empty. Springs back on release. |
| Neighbor fetch timing | Unchanged. Still on-demand at list ends via `/neighbor`. No eager pre-fetch on open. |
| Slot-loading state | No spinner inside the slot. Empty/blank slot is acceptable; preload usually beats the gesture. |
| Reduced motion | `prefers-reduced-motion: reduce` → controller short-circuits to today's instant-swap behavior. Track stays in DOM but never transitions. |

## Architecture

A three-slot **track** replaces the single `<img>` element inside the dialog. The track is a positioned container holding three absolutely-positioned image slots:

```
+---------------------- viewport ----------------------+
|                                                      |
|  [ slot.prev ][ slot.curr ][ slot.next ]             |   ← track (translate3d)
|     x=-100%      x=0          x=+100%                |
|                                                      |
+------------------------------------------------------+
```

The track is the only element that moves. Slots stay at fixed offsets relative to the track; the user sees them slide because the track translates under the dialog's overflow clip. After a commit, the controller re-points each slot's `src` at the new (prev, curr, next) photos and resets the track's transform to `0` in the same frame — no DOM churn, no extra image requests beyond the existing preload.

### DOM (template)

In `templates/public/event/photos.html.twig`, replace the single `<img data-lightbox-target="image">` with:

```html
<div data-lightbox-target="track"
     class="lightbox-track absolute inset-0 will-change-transform touch-pan-y">
    <img data-lightbox-target="slotPrev"
         alt=""
         class="lightbox-slot absolute inset-0 m-auto max-w-full max-h-full object-contain select-none"
         style="transform: translate3d(-100%, 0, 0);">
    <img data-lightbox-target="slotCurr"
         alt="{{ event.name }}"
         class="lightbox-slot absolute inset-0 m-auto max-w-full max-h-full object-contain select-none"
         style="transform: translate3d(0, 0, 0);">
    <img data-lightbox-target="slotNext"
         alt=""
         class="lightbox-slot absolute inset-0 m-auto max-w-full max-h-full object-contain select-none"
         style="transform: translate3d(100%, 0, 0);">
</div>
```

The dialog body stays the same flex container; the track fills it. The `image` target is removed. Spinner and error overlays stay where they are (they reference the centre slot's load state, not a specific node).

Minimal CSS lives inline via Tailwind utilities except for a transition class added by the controller:

```css
.lightbox-track.is-animating { transition: transform 250ms cubic-bezier(0.22, 0.61, 0.36, 1); }
```

This class is the only piece of bespoke CSS; it can live in a `<style>` block in the template or be added to a stylesheet that the Asset Mapper already serves.

### Stimulus controller (`assets/controllers/lightbox_controller.js`)

#### Targets

Add `track`, `slotPrev`, `slotCurr`, `slotNext`. Remove `image`. The existing `spinner` and `error` overlays continue to apply to the active (centre) slot.

#### New state

```js
this.slots = { prev: this.slotPrevTarget, curr: this.slotCurrTarget, next: this.slotNextTarget };
this.swipe = null;          // existing pointer-tracking struct, extended
this.committing = false;    // a commit/cancel animation is in flight
this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
```

#### Slot rendering

```js
renderSlots() {
    const curr = this.photos[this.activeIndex] ?? null;
    const prev = this.photos[this.activeIndex - 1] ?? null;
    const next = this.photos[this.activeIndex + 1] ?? null;
    this.assignSlot(this.slots.prev, prev);
    this.assignSlot(this.slots.curr, curr);
    this.assignSlot(this.slots.next, next);
}

assignSlot(img, photo) {
    const src = photo ? photo.previewUrl : '';
    if (img.dataset.photoId !== (photo?.id ?? '')) {
        img.dataset.photoId = photo?.id ?? '';
        img.src = src;
    }
}
```

`load`/`error` listeners migrate from the single `image` to `slotCurr`. Spinner timer logic is unchanged; it only watches the centre slot.

#### Gesture state machine

```
pointerdown (touch|pen, button 0, no swipe in progress, not committing)
    → record { pointerId, startX, startY, startT, lastX, lastT, tracking: false, axisLocked: false }

pointermove
    if not axisLocked:
        dx, dy = current - start
        if |dy| > VERTICAL_RELEASE and |dy| > |dx|:  abandon  (let page scroll)
        if |dx| > AXIS_LOCK (8px):
            axisLocked = true; tracking = true
            track.setPointerCapture(pointerId); event.preventDefault()
    if tracking:
        apply translate; update lastX/lastT for velocity sampling

pointerup
    if not tracking:  return  (click passes through to arrow/close buttons)
    decide commit/cancel; animate

pointercancel
    if tracking:  animate cancel
    clear swipe
```

Constants:

```js
const AXIS_LOCK = 8;            // px before we commit to horizontal
const VERTICAL_RELEASE = 10;    // existing — abort to vertical scroll
const COMMIT_RATIO = 0.25;      // fraction of viewport width
const FLICK_VELOCITY = 0.5;     // px / ms
const RUBBER_BAND = 0.4;
const SNAP_MS = 250;
```

#### Translate while dragging

`hasInMemoryNeighbor(direction)` returns `true` iff `photos[activeIndex ± 1]` is defined — i.e. the slot is already populated. This is the **only** signal that determines rubber-band, intentionally ignoring the `noNeighborFor` memo (see "Boundary semantics" below).

```js
hasInMemoryNeighbor(direction) {
    return direction === 'next'
        ? this.photos[this.activeIndex + 1] !== undefined
        : this.photos[this.activeIndex - 1] !== undefined;
}

applyDrag(dx) {
    const direction = dx < 0 ? 'next' : 'prev';
    const damped = this.hasInMemoryNeighbor(direction) ? dx : dx * RUBBER_BAND;
    this.trackTarget.style.transform = `translate3d(${damped}px, 0, 0)`;
}
```

The slot at the corresponding side is whatever `renderSlots()` last assigned — at list end the off-screen slot has `src=""` and shows blank. The rubber-band damping plus the empty slot together communicate "nothing more".

### Boundary semantics

The controller already keeps two boundary memos:

- `photos[]` — in-memory list; rendered into slots.
- `noNeighborFor[direction]` — Set of photo ids for which `/neighbor` returned 204.

We deliberately conflate "no in-memory neighbor" with "rubber-band" (rather than checking `noNeighborFor` directly). Consequence:

1. At list end with `/neighbor` not yet asked, the first swipe rubber-bands and springs back.
2. To avoid that swipe feeling broken (silent no-op), `pointerdown` at a list end fires a **background** `fetchNeighborForActive(direction)` if neither `photos[idx ± 1]` is defined nor `noNeighborFor[direction]` already has the current id. When that promise resolves with a photo, the controller appends/prepends it to `photos[]` and re-runs `renderSlots()`. The next swipe drags freely.
3. Commit (`distance|velocity` exceeded) is gated on `hasInMemoryNeighbor()`: if false, the gesture becomes a cancel (animate back to 0) — not a no-op silent advance.

This keeps the gesture loop synchronous with the in-memory model.

#### Commit / cancel

```js
endSwipe(dxAtRelease, vAtRelease) {
    const w = this.trackTarget.clientWidth;
    const goingLeft = dxAtRelease < 0;
    const direction = goingLeft ? 'next' : 'prev';
    const distanceCommit = Math.abs(dxAtRelease) > w * COMMIT_RATIO;
    const velocityCommit = Math.abs(vAtRelease) > FLICK_VELOCITY && Math.sign(vAtRelease) === Math.sign(dxAtRelease);
    const commit = (distanceCommit || velocityCommit) && this.hasInMemoryNeighbor(direction);
    if (commit) {
        this.animateTo(goingLeft ? -w : w, () => this.advance(direction));
    } else {
        this.animateTo(0, () => {});
    }
}

animateTo(targetPx, onDone) {
    this.committing = true;
    this.trackTarget.classList.add('is-animating');
    this.trackTarget.style.transform = `translate3d(${targetPx}px, 0, 0)`;
    const finish = () => {
        this.trackTarget.removeEventListener('transitionend', finish);
        this.trackTarget.classList.remove('is-animating');
        this.trackTarget.style.transform = 'translate3d(0, 0, 0)';
        this.committing = false;
        onDone();
    };
    this.trackTarget.addEventListener('transitionend', finish);
    setTimeout(finish, SNAP_MS + 50);   // safety net if transitionend doesn't fire
}
```

The 50 ms safety-net timer is paired with an idempotency guard inside `finish` — calling it twice is harmless because the listener is removed on first invocation.

#### Advance (commit path)

After the track has animated to `±w` and reset to 0, the commit callback performs the model swap *without re-animating*:

```js
advance(direction) {
    if (direction === 'next') this.nextImmediate();
    else this.previousImmediate();
}
```

`nextImmediate()` / `previousImmediate()` are the existing bodies of `next()` / `previous()` (including their on-demand `/neighbor` fetch and the `goTo()` call). `goTo()`'s old `swapImage(photo)` is replaced with `renderSlots()` plus the existing spinner/preload bookkeeping — i.e. the same helper updates all three slots from `activeIndex`.

#### Keyboard / button nav

```js
async next() {
    if (this.activeIndex === null || this.committing) return;
    if (this.reducedMotion) { return this.nextImmediate(); }
    const w = this.trackTarget.clientWidth;
    this.animateTo(-w, () => this.nextImmediate());
}
```

`nextImmediate()` is the old body of `next()` (existing fetch-on-demand + `goTo`). Same shape for `previous()`.

#### Reduced motion

`this.reducedMotion === true` → `pointerdown` returns early (no drag), and `next()`/`previous()` skip `animateTo` and call `*Immediate()` directly. The track still exists but its transform never changes from `translate3d(0,0,0)`.

#### Multi-touch

Second `pointerdown` while a swipe is in progress is ignored (`if (this.swipe) return`). The first pointer keeps capture until release/cancel.

### Edge cases

- **Vertical scroll inside the dialog.** The dialog body has no vertical overflow today, but `touch-action: pan-y` on the track and the existing `VERTICAL_RELEASE` axis-abandon path ensure vertical gestures still propagate naturally.
- **Pointer cancel mid-drag.** Cancel animation back to 0. Capture is implicitly released by the browser.
- **Image load completing mid-drag.** Slot's `src` was set during `renderSlots()`; the network/decoding finishes whenever it finishes, no controller involvement.
- **Resize during drag.** Out of scope — orientation changes are rare during a single swipe and the next `pointerdown` resamples the viewport width.
- **Tap on the centre slot.** With AXIS_LOCK at 8 px and `tracking=false` until lock, a tap never enters tracking and the click passes through (e.g. for any future tap-to-close behavior; today the controller has none).
- **Neighbor arrives after rubber-band release.** Harmless — the next swipe will see the populated `photos[]` and either rubber-band no longer applies, or it does and the new fetch retries.

## Implementation plan (preview, not exhaustive)

1. Template change — replace `<img data-lightbox-target="image">` with the track + three slots; add the `is-animating` CSS rule.
2. Controller — add new targets, replace `swapImage` with `renderSlots`, add the gesture state machine, wrap `next`/`previous` with `animateTo`, wire reduced-motion gate.
3. Manual verification (the canonical checklist; see Verification).
4. Squash + open PR referencing #70.

The detailed step list belongs in the implementation plan (writing-plans skill).

## Verification (manual)

Open the public lightbox on a phone (or Chrome DevTools device emulation with touch events) and confirm:

- Slow drag right/left ≤ 25% width and release → image springs back to current, no swap.
- Drag past 25% width → snaps to neighbor with one transition.
- Quick flick under 25% width but above ~0.5 px/ms → still commits to neighbor.
- Drag at the first photo to the right (no prev) → damped 0.4× drag, springs back on release; left arrow remains hidden.
- Drag at the last loaded photo to the left (no next) → triggers `/neighbor` fetch on commit; the slot becomes blank briefly until preload populates it.
- Vertical swipe (e.g. notification shade) → carousel does not engage; page scroll behaves normally.
- Mouse drag inside the lightbox on desktop → no movement (mouse path unchanged).
- Keyboard left/right and on-screen ‹ › buttons → snap with the same 250 ms transition.
- `prefers-reduced-motion: reduce` (DevTools rendering pane) → drag inert, arrows do instant swap.
- Browser back after a swipe-commit returns to the previous photo (URL hash flow unchanged).

## Risks

- **Memory of three decoded images.** Each preview is small; three live `<img>` elements per dialog instance is a non-issue. The original `preload(index)` already kept the same images decoded via off-DOM Image objects.
- **`transitionend` not firing.** Mitigated by the 50 ms safety-net timer with idempotent `finish()`.
- **First-frame layout pop.** The track is positioned identically to today's `<img>` (`absolute inset-0` + `object-contain`). Switching to the new DOM in one commit avoids a mixed-state render.

## Out of scope (future, not in this issue)

- Zoom / pinch.
- Swipe-down to dismiss.
- Pre-fetching the `/neighbor` endpoint eagerly on open (could be added later if rubber-band-at-end is felt as a regression).
