# Mobile Lightbox Carousel Swipe Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the instant-swap mobile swipe in the public photo lightbox with a true carousel transition: the current image follows the finger, the neighbor slides in alongside, and on release the controller snaps to the neighbor or springs back.

**Architecture:** A track element holds three absolutely-positioned image slots (prev / curr / next). On `pointerdown` (touch+pen only) the controller axis-locks, translates the track with the finger, rubber-bands at in-memory boundaries, and on release animates a commit (snap to neighbor) or cancel (spring back) using a CSS transition on `transform`. Keyboard arrows and on-screen prev/next buttons reuse the same commit animation. `prefers-reduced-motion` users keep today's instant-swap behavior.

**Tech Stack:** Stimulus 3 (`@hotwired/stimulus`), Symfony Asset Mapper, Tailwind CSS, Twig templates. No JS test runner in this project — verification is via grumphp gates (PHP/template syntax) and the manual checklist in the spec.

**Spec:** [`docs/superpowers/specs/2026-06-15-70-mobile-lightbox-carousel-swipe-design.md`](../specs/2026-06-15-70-mobile-lightbox-carousel-swipe-design.md)

**Branch:** `feature/70-mobile-lightbox-carousel-swipe` (already created)

---

## Files

- **Modify:** `templates/public/event/photos.html.twig` — swap the single `<img data-lightbox-target="image">` for a track + 3 slot images; add a single `.lightbox-track.is-animating` CSS rule inside a `<style>` block in this template.
- **Modify:** `assets/controllers/lightbox_controller.js` — the entire change happens here: new targets, `renderSlots`, gesture state machine, `animateTo` commit, rubber-band, reduced-motion short-circuit.

No PHP/Twig logic outside the template changes. No new files. No new dependencies. No DB or migration.

---

## Note on TDD

This project does not have a JS test runner (PHPUnit covers PHP only; the JS bundle has no Jest/Vitest setup, see `CLAUDE.md` "Tests"). The plan adopts an analogue of TDD by:

1. After each implementation task, executing the manual verification checklist that targets that task.
2. Running `vendor/bin/grumphp run` before commit so phpstan / phpcs / `doctrine:schema:validate` / branch-name gates pass (none of these read the JS, but they enforce branch-name and commit-message rules).
3. Hard-checking each step with browser DevTools (touch emulation) before moving on.

Manual verification commands are listed inline per task.

---

## Task 1: Replace lightbox image with track + 3 slots

**Files:**
- Modify: `templates/public/event/photos.html.twig:106-108` (the `<img data-lightbox-target="image">` block)

- [ ] **Step 1: Replace the image element with the track DOM**

In `templates/public/event/photos.html.twig`, replace this:

```twig
                        <img data-lightbox-target="image"
                             alt="{{ event.name }}"
                             class="max-w-full max-h-full object-contain select-none touch-pan-y">
```

with:

```twig
                        <div data-lightbox-target="track"
                             class="lightbox-track absolute inset-0 overflow-hidden will-change-transform touch-pan-y">
                            <img data-lightbox-target="slotPrev"
                                 alt=""
                                 aria-hidden="true"
                                 class="lightbox-slot absolute inset-0 m-auto max-w-full max-h-full object-contain select-none pointer-events-none"
                                 style="transform: translate3d(-100%, 0, 0);">
                            <img data-lightbox-target="slotCurr"
                                 alt="{{ event.name }}"
                                 class="lightbox-slot absolute inset-0 m-auto max-w-full max-h-full object-contain select-none"
                                 style="transform: translate3d(0, 0, 0);">
                            <img data-lightbox-target="slotNext"
                                 alt=""
                                 aria-hidden="true"
                                 class="lightbox-slot absolute inset-0 m-auto max-w-full max-h-full object-contain select-none pointer-events-none"
                                 style="transform: translate3d(100%, 0, 0);">
                        </div>
```

- [ ] **Step 2: Add the transition CSS rule**

At the top of the same template, immediately after the `{% block body %}` line (or wherever the first non-extend Twig block opens), add a one-off `<style>` block:

```twig
<style>
    .lightbox-track { position: absolute; inset: 0; transform: translate3d(0, 0, 0); }
    .lightbox-track.is-animating { transition: transform 250ms cubic-bezier(0.22, 0.61, 0.36, 1); }
</style>
```

If the template already has a `<style>` block, append the rules to that block instead of creating a new one.

- [ ] **Step 3: Verify the template still renders**

Run:

```bash
docker compose up -d
```

Then open `http://localhost:8080/e/<some-event-slug>/photos` (use the slug of an event with at least one Ready photo). Confirm:
- Grid still renders.
- Clicking a thumbnail still opens the dialog.
- The centre image still appears (it will be the only one visible because the prev/next slots are translated off-screen and have empty `src`).
- Existing arrow buttons still navigate (the controller hasn't been updated yet, so it will throw a target-missing error in the console — that's expected and gets fixed in Task 2).

Expected: visual sanity, even with a runtime error in the console.

- [ ] **Step 4: Run gates**

```bash
vendor/bin/grumphp run
```

Expected: PASS (template change is syntactic Twig only; no PHP behavior changes).

- [ ] **Step 5: Commit**

```bash
git add templates/public/event/photos.html.twig
git commit -m "70 - lightbox: replace image with carousel track + 3 slots"
```

---

## Task 2: Migrate controller from `image` target to slot targets

**Files:**
- Modify: `assets/controllers/lightbox_controller.js` (whole file in incremental steps; this task only does the rename + extract `renderSlots`)

- [ ] **Step 1: Update static targets**

In `assets/controllers/lightbox_controller.js`, change the `static targets` block (lines 9-18) from:

```js
    static targets = [
        'trigger',
        'dialog',
        'image',
        'spinner',
        'error',
        'prevButton',
        'nextButton',
        'counter',
    ];
```

to:

```js
    static targets = [
        'trigger',
        'dialog',
        'track',
        'slotPrev',
        'slotCurr',
        'slotNext',
        'spinner',
        'error',
        'prevButton',
        'nextButton',
        'counter',
    ];
```

- [ ] **Step 2: Replace `imageTarget` event wiring in `connect()` with `slotCurrTarget`**

In `connect()` (around lines 64-69), change:

```js
        this.imageTarget.addEventListener('load', this.onImageLoad);
        this.imageTarget.addEventListener('error', this.onImageError);
        this.imageTarget.addEventListener('pointerdown', this.onPointerDown);
        this.imageTarget.addEventListener('pointermove', this.onPointerMove);
        this.imageTarget.addEventListener('pointerup', this.onPointerUp);
        this.imageTarget.addEventListener('pointercancel', this.onPointerCancel);
```

to:

```js
        this.slotCurrTarget.addEventListener('load', this.onImageLoad);
        this.slotCurrTarget.addEventListener('error', this.onImageError);
        this.trackTarget.addEventListener('pointerdown', this.onPointerDown);
        this.trackTarget.addEventListener('pointermove', this.onPointerMove);
        this.trackTarget.addEventListener('pointerup', this.onPointerUp);
        this.trackTarget.addEventListener('pointercancel', this.onPointerCancel);
```

Pointer events now live on the **track**, not the centre slot — that way drags that start on a transparent area still count.

- [ ] **Step 3: Replace `imageTarget` cleanup in `disconnect()`**

In `disconnect()` (around lines 82-89), change:

```js
        if (this.hasImageTarget) {
            this.imageTarget.removeEventListener('load', this.onImageLoad);
            this.imageTarget.removeEventListener('error', this.onImageError);
            this.imageTarget.removeEventListener('pointerdown', this.onPointerDown);
            this.imageTarget.removeEventListener('pointermove', this.onPointerMove);
            this.imageTarget.removeEventListener('pointerup', this.onPointerUp);
            this.imageTarget.removeEventListener('pointercancel', this.onPointerCancel);
        }
```

to:

```js
        if (this.hasSlotCurrTarget) {
            this.slotCurrTarget.removeEventListener('load', this.onImageLoad);
            this.slotCurrTarget.removeEventListener('error', this.onImageError);
        }
        if (this.hasTrackTarget) {
            this.trackTarget.removeEventListener('pointerdown', this.onPointerDown);
            this.trackTarget.removeEventListener('pointermove', this.onPointerMove);
            this.trackTarget.removeEventListener('pointerup', this.onPointerUp);
            this.trackTarget.removeEventListener('pointercancel', this.onPointerCancel);
        }
```

- [ ] **Step 4: Replace `swapImage(photo)` with `renderSlots()`**

Replace the existing `swapImage(photo)` method (lines 267-278) with:

```js
    renderSlots() {
        if (this.activeIndex === null) return;
        const curr = this.photos[this.activeIndex] ?? null;
        const prev = this.photos[this.activeIndex - 1] ?? null;
        const next = this.photos[this.activeIndex + 1] ?? null;

        this.clearSpinnerTimer();
        const state = curr ? this.preloadState.get(curr.id) : null;
        if (state === 'loaded') {
            this.hideSpinner();
        } else {
            this.spinnerTimer = setTimeout(() => this.showSpinner(), SPINNER_DELAY_MS);
        }

        this.assignSlot(this.slotPrevTarget, prev);
        this.assignSlot(this.slotCurrTarget, curr);
        this.assignSlot(this.slotNextTarget, next);
    }

    assignSlot(img, photo) {
        const desiredId = photo ? String(photo.id) : '';
        if (img.dataset.photoId === desiredId) return;
        img.dataset.photoId = desiredId;
        img.src = photo ? photo.previewUrl : '';
    }
```

- [ ] **Step 5: Replace the three call sites of `swapImage`**

Search for `this.swapImage(` in the file (3 occurrences in `open()`, `goTo()`, and indirectly via the method) and replace each:

```js
        this.swapImage(photo);
```

with:

```js
        this.renderSlots();
```

The `photo` argument disappears — `renderSlots` reads `this.activeIndex` directly. Drop the `const photo = this.photos[index];` lines in `open()` and `goTo()` if they become unused; keep them if they're still used for the `history.pushState/replaceState` calls a few lines down.

- [ ] **Step 6: Update `onImageLoad` / `onImageError` to track the centre slot's photo**

The existing `onImageLoad` / `onImageError` call `this.currentPhoto()` and write into `preloadState`. They keep working because `currentPhoto()` returns `photos[activeIndex]` regardless of which DOM node fired — no change needed. Verify by re-reading the methods (lines 280-297) and confirming.

- [ ] **Step 7: Smoke-test the dialog**

```bash
docker compose up -d
```

Open `http://localhost:8080/e/<event>/photos`, click a thumbnail. Confirm:
- Dialog opens with the current photo visible.
- Console has **no** target-missing errors.
- Spinner and error overlays still work (force an error by mangling `previewUrl` temporarily if desired, but optional).
- Keyboard `←` / `→` and the on-screen prev/next buttons still navigate (instantly — animation comes in Task 5).

- [ ] **Step 8: Run gates and commit**

```bash
vendor/bin/grumphp run
```

Expected: PASS.

```bash
git add assets/controllers/lightbox_controller.js
git commit -m "70 - lightbox: migrate controller from image target to slot targets"
```

---

## Task 3: Extract `nextImmediate` / `previousImmediate`

**Files:**
- Modify: `assets/controllers/lightbox_controller.js`

This task is a pure refactor: rename the bodies of `next()` / `previous()` to `nextImmediate()` / `previousImmediate()` so Task 5 can wrap them in `animateTo()`.

- [ ] **Step 1: Rename `next()` to `nextImmediate()`**

Change (lines 120-134):

```js
    async next() {
        if (this.activeIndex === null) return;
        if (this.activeIndex < this.photos.length - 1) {
            this.goTo(this.activeIndex + 1);
            return;
        }
        const neighbor = await this.fetchNeighborForActive('next');
        if (!neighbor) {
            this.updateArrows();
            return;
        }
        neighbor.rank = this.photos[this.photos.length - 1].rank + 1;
        this.photos.push(neighbor);
        this.goTo(this.photos.length - 1);
    }
```

to:

```js
    async nextImmediate() {
        if (this.activeIndex === null) return;
        if (this.activeIndex < this.photos.length - 1) {
            this.goTo(this.activeIndex + 1);
            return;
        }
        const neighbor = await this.fetchNeighborForActive('next');
        if (!neighbor) {
            this.updateArrows();
            return;
        }
        neighbor.rank = this.photos[this.photos.length - 1].rank + 1;
        this.photos.push(neighbor);
        this.goTo(this.photos.length - 1);
    }

    next() {
        return this.nextImmediate();
    }
```

(`next()` stays as a synchronous-looking wrapper for now; Task 5 will replace its body with the animated path.)

- [ ] **Step 2: Rename `previous()` to `previousImmediate()`**

Same shape (lines 136-151):

```js
    async previousImmediate() {
        if (this.activeIndex === null) return;
        if (this.activeIndex > 0) {
            this.goTo(this.activeIndex - 1);
            return;
        }
        const neighbor = await this.fetchNeighborForActive('prev');
        if (!neighbor) {
            this.updateArrows();
            return;
        }
        neighbor.rank = this.photos[0].rank - 1;
        this.photos.unshift(neighbor);
        this.activeIndex += 1;
        this.goTo(0);
    }

    previous() {
        return this.previousImmediate();
    }
```

- [ ] **Step 3: Smoke-test**

Reload the photos page, open the lightbox, press `←` / `→` and click the on-screen prev/next buttons. Confirm: identical behavior to before this task (instant swap).

- [ ] **Step 4: Run gates and commit**

```bash
vendor/bin/grumphp run
git add assets/controllers/lightbox_controller.js
git commit -m "70 - lightbox: extract nextImmediate/previousImmediate"
```

---

## Task 4: Gesture state machine + axis lock (no animation yet)

**Files:**
- Modify: `assets/controllers/lightbox_controller.js`

Add the constants, replace pointer handlers, and track drag distance. The track does not yet visually translate — this task only proves the state machine fires cleanly and the existing release-direction commit (Task 3 wrappers) still works.

- [ ] **Step 1: Replace gesture constants**

At the top of the file, replace:

```js
const SWIPE_THRESHOLD = 50;
const VERTICAL_RELEASE = 10;
```

with:

```js
const AXIS_LOCK = 8;            // px before we commit to horizontal
const VERTICAL_RELEASE = 10;    // |dy| at which we abandon to vertical scroll
const COMMIT_RATIO = 0.25;      // fraction of viewport width
const FLICK_VELOCITY = 0.5;     // px / ms
const RUBBER_BAND = 0.4;
const SNAP_MS = 250;
```

`SPINNER_DELAY_MS` and `HASH_PATTERN` stay as-is. `SWIPE_THRESHOLD` is removed.

- [ ] **Step 2: Add `reducedMotion` and `committing` to constructor state**

In `connect()`, immediately after the line `this.swipe = null;` (around line 40), add:

```js
        this.committing = false;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
```

- [ ] **Step 3: Replace `onPointerDown`**

Replace the existing `onPointerDown(event)` (lines 311-320) with:

```js
    onPointerDown(event) {
        if (this.committing) return;
        if (this.swipe) return;                                    // multi-touch: ignore
        if (event.pointerType !== 'touch' && event.pointerType !== 'pen') return;
        if (event.button !== 0) return;
        this.swipe = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            lastX: event.clientX,
            lastT: event.timeStamp,
            startT: event.timeStamp,
            tracking: false,
            axisLocked: false,
            abandoned: false,
        };

        // Background-prefetch the boundary neighbor so the *next* swipe drags freely.
        if (this.activeIndex !== null) {
            if (this.activeIndex === this.photos.length - 1) {
                this.fetchNeighborForActive('next').then((n) => this.onBoundaryNeighborResolved('next', n));
            }
            if (this.activeIndex === 0) {
                this.fetchNeighborForActive('prev').then((n) => this.onBoundaryNeighborResolved('prev', n));
            }
        }
    }
```

- [ ] **Step 4: Add `onBoundaryNeighborResolved`**

Add this method anywhere in the class body (e.g. just below `fetchNeighborForActive`):

```js
    onBoundaryNeighborResolved(direction, neighbor) {
        if (!neighbor || this.activeIndex === null) return;
        if (direction === 'next') {
            if (this.activeIndex !== this.photos.length - 1) return;
            neighbor.rank = this.photos[this.photos.length - 1].rank + 1;
            this.photos.push(neighbor);
        } else {
            if (this.activeIndex !== 0) return;
            neighbor.rank = this.photos[0].rank - 1;
            this.photos.unshift(neighbor);
            this.activeIndex += 1;
        }
        this.renderSlots();
        this.updateArrows();
    }
```

- [ ] **Step 5: Replace `onPointerMove`**

Replace lines 322-331 with:

```js
    onPointerMove(event) {
        if (!this.swipe || event.pointerId !== this.swipe.pointerId || this.swipe.abandoned) return;
        const dx = event.clientX - this.swipe.startX;
        const dy = event.clientY - this.swipe.startY;

        if (!this.swipe.axisLocked) {
            if (Math.abs(dy) > VERTICAL_RELEASE && Math.abs(dy) > Math.abs(dx)) {
                this.swipe.abandoned = true;
                return;
            }
            if (Math.abs(dx) > AXIS_LOCK) {
                this.swipe.axisLocked = true;
                this.swipe.tracking = true;
                try { this.trackTarget.setPointerCapture(this.swipe.pointerId); } catch (_) {}
            } else {
                return;
            }
        }

        event.preventDefault();
        this.swipe.lastX = event.clientX;
        this.swipe.lastT = event.timeStamp;
        // Visual translation comes in Task 5.
    }
```

- [ ] **Step 6: Replace `onPointerUp`**

Replace lines 333-345 with:

```js
    onPointerUp(event) {
        if (!this.swipe || event.pointerId !== this.swipe.pointerId) return;
        const swipe = this.swipe;
        this.swipe = null;
        if (!swipe.tracking) return;                               // tap / abandoned vertical

        const dx = event.clientX - swipe.startX;
        const dt = Math.max(1, event.timeStamp - swipe.lastT);
        const v = (event.clientX - swipe.lastX) / dt;
        const direction = dx < 0 ? 'next' : 'prev';

        const w = this.trackTarget.clientWidth;
        const distanceCommit = Math.abs(dx) > w * COMMIT_RATIO;
        const velocityCommit = Math.abs(v) > FLICK_VELOCITY && Math.sign(v) === Math.sign(dx);
        const commit = (distanceCommit || velocityCommit) && this.hasInMemoryNeighbor(direction);

        if (commit) {
            if (direction === 'next') this.nextImmediate();
            else this.previousImmediate();
        }
        // Visual snap/spring comes in Task 5.
    }
```

- [ ] **Step 7: Replace `onPointerCancel`**

Replace lines 347-349 with:

```js
    onPointerCancel(event) {
        if (!this.swipe || event.pointerId !== this.swipe.pointerId) return;
        this.swipe = null;
        // Visual reset comes in Task 5.
    }
```

- [ ] **Step 8: Add `hasInMemoryNeighbor`**

Add this method anywhere in the class body (e.g. just above `currentPhoto`):

```js
    hasInMemoryNeighbor(direction) {
        if (this.activeIndex === null) return false;
        return direction === 'next'
            ? this.photos[this.activeIndex + 1] !== undefined
            : this.photos[this.activeIndex - 1] !== undefined;
    }
```

- [ ] **Step 9: Manual verification**

```bash
docker compose up -d
```

In Chrome DevTools, toggle **Device Mode** (Cmd+Shift+M) so pointer events report `pointerType: "touch"`. Pick a phone preset. Open the lightbox on a photo near the middle of the list and verify:
- A slow horizontal drag past 25% width logs no errors and advances to the neighbor on release (instant — no animation yet).
- A drag of < 25% width and slow release does **not** advance.
- A quick flick of small distance advances (velocity rule).
- A vertical swipe inside the dialog does not engage (no swipe → no advance).
- On desktop without device emulation (so `pointerType === 'mouse'`), drag inside the lightbox does nothing.
- Console is clean.

- [ ] **Step 10: Run gates and commit**

```bash
vendor/bin/grumphp run
git add assets/controllers/lightbox_controller.js
git commit -m "70 - lightbox: touch/pen gesture state machine with axis lock"
```

---

## Task 5: Drag translation + commit/cancel animation

**Files:**
- Modify: `assets/controllers/lightbox_controller.js`

Now wire the visual translation: track follows the finger during drag (with rubber-band), and on release we animate to ±viewport-width (commit) or 0 (cancel) before swapping photos.

- [ ] **Step 1: Add `applyDrag(dx)` helper**

Add this method to the class:

```js
    applyDrag(dx) {
        const direction = dx < 0 ? 'next' : 'prev';
        const damped = this.hasInMemoryNeighbor(direction) ? dx : dx * RUBBER_BAND;
        this.trackTarget.style.transform = `translate3d(${damped}px, 0, 0)`;
    }
```

- [ ] **Step 2: Add `animateTo(targetPx, onDone)` helper**

Add this method to the class:

```js
    animateTo(targetPx, onDone) {
        this.committing = true;
        let finished = false;
        const finish = () => {
            if (finished) return;
            finished = true;
            this.trackTarget.removeEventListener('transitionend', onEnd);
            clearTimeout(safety);
            this.trackTarget.classList.remove('is-animating');
            this.trackTarget.style.transform = 'translate3d(0, 0, 0)';
            this.committing = false;
            onDone();
        };
        const onEnd = (e) => { if (e.target === this.trackTarget) finish(); };
        this.trackTarget.addEventListener('transitionend', onEnd);
        const safety = setTimeout(finish, SNAP_MS + 50);
        // Force a layout flush so the transition picks up the new transform.
        this.trackTarget.classList.add('is-animating');
        // eslint-disable-next-line no-unused-expressions
        this.trackTarget.offsetWidth;
        this.trackTarget.style.transform = `translate3d(${targetPx}px, 0, 0)`;
    }
```

- [ ] **Step 3: Call `applyDrag` from `onPointerMove`**

In `onPointerMove`, replace the trailing comment `// Visual translation comes in Task 5.` with:

```js
        this.applyDrag(event.clientX - this.swipe.startX);
```

- [ ] **Step 4: Replace `onPointerUp` body with animated commit/cancel**

Replace the entire `onPointerUp` body from Task 4 with:

```js
    onPointerUp(event) {
        if (!this.swipe || event.pointerId !== this.swipe.pointerId) return;
        const swipe = this.swipe;
        this.swipe = null;
        if (!swipe.tracking) return;

        const dx = event.clientX - swipe.startX;
        const dt = Math.max(1, event.timeStamp - swipe.lastT);
        const v = (event.clientX - swipe.lastX) / dt;
        const direction = dx < 0 ? 'next' : 'prev';

        const w = this.trackTarget.clientWidth;
        const distanceCommit = Math.abs(dx) > w * COMMIT_RATIO;
        const velocityCommit = Math.abs(v) > FLICK_VELOCITY && Math.sign(v) === Math.sign(dx);
        const commit = (distanceCommit || velocityCommit) && this.hasInMemoryNeighbor(direction);

        if (commit) {
            this.animateTo(direction === 'next' ? -w : w, () => {
                if (direction === 'next') this.nextImmediate();
                else this.previousImmediate();
            });
        } else {
            this.animateTo(0, () => {});
        }
    }
```

- [ ] **Step 5: Reset transform on `pointercancel`**

Replace `onPointerCancel` with:

```js
    onPointerCancel(event) {
        if (!this.swipe || event.pointerId !== this.swipe.pointerId) return;
        const wasTracking = this.swipe.tracking;
        this.swipe = null;
        if (wasTracking) this.animateTo(0, () => {});
    }
```

- [ ] **Step 6: Manual verification**

Reload, in device-mode (touch emulation):
- Slow drag right by ~20% width and release → image springs back, no swap, smooth 250 ms transition.
- Drag past 25% → snaps to neighbor in one transition, then the new photo is stable in centre.
- Quick flick under 25% → still commits (velocity).
- At the first photo, drag right → 0.4× damped drag, springs back; left arrow remains hidden.
- At the last loaded photo, drag left → 0.4× damped drag, springs back. Background `/neighbor` request fires in Network tab.
- After background `/neighbor` resolves, do a second drag left → drags freely (in-memory neighbor now exists).
- Vertical swipe → no horizontal movement; carousel stays at 0.
- During an animation, additional pointer events are ignored (no flicker).

- [ ] **Step 7: Run gates and commit**

```bash
vendor/bin/grumphp run
git add assets/controllers/lightbox_controller.js
git commit -m "70 - lightbox: animate carousel drag with commit/cancel"
```

---

## Task 6: Animate keyboard arrows and on-screen prev/next buttons

**Files:**
- Modify: `assets/controllers/lightbox_controller.js`

Keyboard/`←`/`→` and the visible prev/next buttons currently call `next()` / `previous()`, which in Task 3 became thin wrappers around `*Immediate()`. We now route those wrappers through `animateTo` so the visual model is consistent.

- [ ] **Step 1: Replace the `next()` wrapper**

Replace the body of `next()` (added in Task 3) with:

```js
    next() {
        if (this.activeIndex === null || this.committing) return;
        if (this.reducedMotion) return this.nextImmediate();
        if (!this.hasInMemoryNeighbor('next')) {
            // Fetch first; arrow click at boundary is the same code path as before.
            return this.nextImmediate();
        }
        const w = this.trackTarget.clientWidth;
        this.animateTo(-w, () => this.nextImmediate());
    }
```

- [ ] **Step 2: Replace the `previous()` wrapper**

```js
    previous() {
        if (this.activeIndex === null || this.committing) return;
        if (this.reducedMotion) return this.previousImmediate();
        if (!this.hasInMemoryNeighbor('prev')) {
            return this.previousImmediate();
        }
        const w = this.trackTarget.clientWidth;
        this.animateTo(w, () => this.previousImmediate());
    }
```

- [ ] **Step 3: Manual verification**

Reload (no device mode needed for keyboard test):
- `←` / `→` and the on-screen `‹` / `›` buttons advance with a 250 ms slide transition matching the swipe commit.
- Holding `→` repeats; each repeat queues only after the previous transition completes (the `committing` guard prevents overlap).
- At a list end where the neighbor hasn't been fetched, the arrow still does its existing on-demand fetch (no animation in that case — `hasInMemoryNeighbor` is false).
- Once the neighbor is in memory, subsequent arrow presses animate.
- Touch swipe still works as in Task 5.
- `pushState` / hash updates still fire (browser back returns to the previous photo).

- [ ] **Step 4: Run gates and commit**

```bash
vendor/bin/grumphp run
git add assets/controllers/lightbox_controller.js
git commit -m "70 - lightbox: animate keyboard and button navigation"
```

---

## Task 7: `prefers-reduced-motion` short-circuit + final manual checklist

**Files:**
- Modify: `assets/controllers/lightbox_controller.js`

`this.reducedMotion` was already wired in Task 4 (constructor) and Task 6 (button/keyboard nav). This task adds the same gate to the touch path and runs the full verification checklist from the spec.

- [ ] **Step 1: Short-circuit `onPointerDown` for reduced motion**

At the top of `onPointerDown`, immediately after the `if (this.committing) return;` line, add:

```js
        if (this.reducedMotion) return;     // reduced-motion users keep instant-swap behavior
```

This stops the controller from initiating any drag. Today's behavior path for reduced-motion users is: the dialog ignores touch drags entirely; navigation happens via taps on the arrow buttons (which in `previous()` / `next()` already short-circuit to `*Immediate()`).

> Note: the issue's "drop back to existing instant-swap if `prefers-reduced-motion` is set" is interpreted here as "no drag gesture at all" rather than "drag-and-then-swap-instantly". Dragging without animation would look broken; pure tap-to-advance is the safer reduced-motion fallback.

- [ ] **Step 2: Full manual verification checklist (from the spec)**

In Chrome DevTools device mode (touch emulation), confirm each:

- [ ] Slow drag right/left ≤ 25% width → springs back, no swap.
- [ ] Drag past 25% width → snaps to neighbor with one transition.
- [ ] Quick flick under 25% width, > 0.5 px/ms → commits to neighbor.
- [ ] First photo, drag right → 0.4× damped, springs back; left arrow hidden.
- [ ] Last loaded photo, drag left → 0.4× damped, springs back; `/neighbor` fires in Network tab; **next** drag left advances (after the prefetch lands).
- [ ] Vertical swipe → carousel does not engage; page scroll works.
- [ ] Mouse drag (device-mode off) inside the lightbox → no movement; arrows still work.
- [ ] Keyboard `←` / `→` and on-screen `‹` / `›` → animate with the same 250 ms snap.
- [ ] `prefers-reduced-motion: reduce` (DevTools → Rendering → Emulate CSS media feature) → drag inert; arrow taps do instant swap.
- [ ] Browser back after a swipe-commit → returns to the previous photo (hash flow intact).
- [ ] Multi-touch (second finger during a swipe) → ignored; first finger keeps capture.

If any of the above fails, fix in place before committing.

- [ ] **Step 3: Run gates and commit**

```bash
vendor/bin/grumphp run
git add assets/controllers/lightbox_controller.js
git commit -m "70 - lightbox: gate drag behind prefers-reduced-motion"
```

---

## Task 8: Push branch and open PR

- [ ] **Step 1: Push the branch**

```bash
git push -u origin feature/70-mobile-lightbox-carousel-swipe
```

- [ ] **Step 2: Open the PR**

```bash
gh pr create --title "70 - mobile lightbox: animate swipe as carousel" --body "$(cat <<'EOF'
## Summary
- On touch/pen pointers, the public lightbox now translates the active image with the finger and slides the preloaded neighbor in alongside.
- Release commits (snap to neighbor) or cancels (spring back) based on distance ≥ 25% viewport width OR release velocity ≥ 0.5 px/ms.
- Keyboard arrows and on-screen prev/next buttons use the same 250 ms snap so the visual model is consistent.
- `prefers-reduced-motion: reduce` users keep the existing instant-swap behavior.

Closes #70.

## Test plan
- [ ] Slow drag < 25% width → springs back.
- [ ] Drag > 25% width → commits to neighbor.
- [ ] Flick (small distance, high velocity) → commits.
- [ ] First photo, drag right → rubber-band, springs back.
- [ ] Last loaded photo, drag left → rubber-band; `/neighbor` prefetch fires; second swipe advances.
- [ ] Vertical swipe → carousel inert, page scrolls.
- [ ] Mouse drag → inert.
- [ ] Keyboard `←` / `→` and arrow buttons → snap-animate.
- [ ] `prefers-reduced-motion: reduce` → drag inert, arrows do instant swap.
- [ ] Browser back after commit → returns to previous photo.
EOF
)"
```

---

## Self-review

**Spec coverage:**
- Track + 3 slots DOM → Task 1.
- `renderSlots` + slot targets → Task 2.
- `nextImmediate` / `previousImmediate` extraction → Task 3.
- Touch/pen gating, axis lock, multi-touch guard, background neighbor prefetch → Task 4.
- Rubber-band, commit (distance OR velocity), `animateTo`, transitionend + safety timer → Task 5.
- Keyboard/button nav animated via the same `animateTo` → Task 6.
- `prefers-reduced-motion` short-circuit → Task 7.
- Full verification checklist → Task 7 step 2.

**Placeholder scan:** all code blocks present; no "TBD" / "handle edge cases" / "similar to Task N" patterns; expected outcomes specified per manual step.

**Type consistency:**
- `renderSlots()` defined Task 2, referenced Task 4 (`onBoundaryNeighborResolved`).
- `hasInMemoryNeighbor(direction)` defined Task 4, referenced Tasks 5 + 6.
- `applyDrag(dx)`, `animateTo(targetPx, onDone)` defined Task 5, referenced Task 6.
- `nextImmediate()` / `previousImmediate()` defined Task 3, referenced Tasks 4/5/6/7.
- `committing` defined Task 4, gated in Tasks 4/5/6.
- `reducedMotion` defined Task 4, used Tasks 6/7.
- Target name `slotCurr` consistent throughout; `slotPrev`/`slotNext` consistent.
- Constants `AXIS_LOCK`, `VERTICAL_RELEASE`, `COMMIT_RATIO`, `FLICK_VELOCITY`, `RUBBER_BAND`, `SNAP_MS` defined Task 4, used Tasks 4/5.

All checks pass.
