import { Controller } from '@hotwired/stimulus';

const AXIS_LOCK = 8;            // px before we commit to horizontal
const VERTICAL_RELEASE = 10;    // |dy| at which we abandon to vertical scroll
const COMMIT_RATIO = 0.10;      // fraction of viewport width a slow drag must cross to commit (flick/velocity commits separately)
const FLICK_VELOCITY = 0.5;     // px / ms
const VELOCITY_WINDOW_MS = 80;
const RUBBER_BAND = 0.4;
const SNAP_MS = 250;
const SPINNER_DELAY_MS = 200;
const HASH_PATTERN = /^#p=(\d+)$/;

export default class extends Controller {
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

    static values = {
        eventSlug: String,
        totalReady: Number,
        preloadWindow: { type: Number, default: 3 },
    };

    connect() {
        this.photos = this.triggerTargets.map((li) => ({
            id: li.dataset.photoId,
            previewUrl: li.dataset.previewUrl,
            rank: Number(li.dataset.photoRank),
            element: li,
        }));

        if (this.photos.length === 0) {
            return;
        }

        this.activeIndex = null;
        this.preloadState = new Map();
        this.spinnerTimer = null;
        this.swipe = null;
        this.committing = false;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.didPushState = false;
        // Per-photo memo: "no further Ready photo exists in this direction".
        // Populated when the neighbor endpoint returns 204; lets us hide the
        // arrow and short-circuit subsequent presses without refetching.
        this.noNeighborFor = { next: new Set(), prev: new Set() };
        this.neighborInFlight = new Map();

        this.onTriggerClick = this.onTriggerClick.bind(this);
        this.onKeyDown = this.onKeyDown.bind(this);
        this.onPopState = this.onPopState.bind(this);
        this.onImageLoad = this.onImageLoad.bind(this);
        this.onImageError = this.onImageError.bind(this);
        this.onPointerDown = this.onPointerDown.bind(this);
        this.onPointerMove = this.onPointerMove.bind(this);
        this.onPointerUp = this.onPointerUp.bind(this);
        this.onPointerCancel = this.onPointerCancel.bind(this);
        this.onDialogClose = this.onDialogClose.bind(this);

        this.element.addEventListener('click', this.onTriggerClick);
        this.dialogTarget.addEventListener('keydown', this.onKeyDown);
        this.dialogTarget.addEventListener('close', this.onDialogClose);
        window.addEventListener('popstate', this.onPopState);

        this.slotCurrTarget.addEventListener('load', this.onImageLoad);
        this.slotCurrTarget.addEventListener('error', this.onImageError);
        this.trackTarget.addEventListener('pointerdown', this.onPointerDown);
        this.trackTarget.addEventListener('pointermove', this.onPointerMove);
        this.trackTarget.addEventListener('pointerup', this.onPointerUp);
        this.trackTarget.addEventListener('pointercancel', this.onPointerCancel);

        this.openFromHashIfPresent();
    }

    disconnect() {
        this.element.removeEventListener('click', this.onTriggerClick);
        if (this.hasDialogTarget) {
            this.dialogTarget.removeEventListener('keydown', this.onKeyDown);
            this.dialogTarget.removeEventListener('close', this.onDialogClose);
        }
        window.removeEventListener('popstate', this.onPopState);

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

        this.clearSpinnerTimer();
    }

    onTriggerClick(event) {
        const trigger = event.target.closest('[data-lightbox-target="trigger"]');
        if (!trigger || !this.element.contains(trigger)) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button === 1) {
            return;
        }
        const index = this.indexOfPhotoId(trigger.dataset.photoId);
        if (index < 0) {
            return;
        }
        event.preventDefault();
        this.open(index);
    }

    onKeyDown(event) {
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            this.next();
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            this.previous();
        }
    }

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
        if (this.activeIndex === null || this.committing) return;
        if (this.reducedMotion) return this.nextImmediate();
        if (!this.hasInMemoryNeighbor('next')) {
            // Fetch first; arrow click at boundary is the same code path as before.
            return this.nextImmediate();
        }
        const w = this.trackTarget.clientWidth;
        this.animateTo(-w, () => this.nextImmediate());
    }

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
        if (this.activeIndex === null || this.committing) return;
        if (this.reducedMotion) return this.previousImmediate();
        if (!this.hasInMemoryNeighbor('prev')) {
            return this.previousImmediate();
        }
        const w = this.trackTarget.clientWidth;
        this.animateTo(w, () => this.previousImmediate());
    }

    async fetchNeighborOf(photoId, direction) {
        if (!photoId) return null;
        if (this.noNeighborFor[direction].has(photoId)) return null;

        const key = `${direction}:${photoId}`;
        if (this.neighborInFlight.has(key)) {
            return this.neighborInFlight.get(key);
        }

        const slug = this.hasEventSlugValue ? this.eventSlugValue : '';
        if (!slug) return null;

        const url = `/e/${encodeURIComponent(slug)}/photos/${encodeURIComponent(photoId)}/neighbor?direction=${direction}`;
        const promise = fetch(url, { headers: { Accept: 'application/json' } })
            .then(async (res) => {
                if (res.status === 204) {
                    this.noNeighborFor[direction].add(photoId);
                    return null;
                }
                if (!res.ok) {
                    return null;
                }
                const data = await res.json();
                if (!data || typeof data.id === 'undefined' || typeof data.previewUrl !== 'string') {
                    return null;
                }
                return { id: String(data.id), previewUrl: data.previewUrl, element: null };
            })
            .catch(() => null)
            .finally(() => {
                this.neighborInFlight.delete(key);
            });

        this.neighborInFlight.set(key, promise);
        return promise;
    }

    async fetchNeighborForActive(direction) {
        const current = this.currentPhoto();
        if (!current) return null;
        return this.fetchNeighborOf(current.id, direction);
    }

    async prefetchBeyondBoundary(direction, count = 2) {
        for (let i = 0; i < count; i++) {
            if (this.activeIndex === null) return;
            const boundary = direction === 'next'
                ? this.photos[this.photos.length - 1]
                : this.photos[0];
            if (!boundary) return;

            const neighbor = await this.fetchNeighborOf(boundary.id, direction);
            if (!neighbor) return;

            if (this.activeIndex === null) return;
            if (this.indexOfPhotoId(neighbor.id) >= 0) continue;

            if (direction === 'next') {
                neighbor.rank = this.photos[this.photos.length - 1].rank + 1;
                this.photos.push(neighbor);
                this.preload(this.photos.length - 1);
                this.assignSlot(this.slotNextTarget, this.photos[this.activeIndex + 1]);
            } else {
                neighbor.rank = this.photos[0].rank - 1;
                this.photos.unshift(neighbor);
                this.activeIndex += 1;
                this.assignSlot(this.slotPrevTarget, this.photos[this.activeIndex - 1]);
            }
            this.updateArrows();
        }
    }

    close() {
        if (this.activeIndex === null) return;
        this.dialogTarget.close();
    }

    onDialogClose() {
        if (this.activeIndex === null) return;
        this.activeIndex = null;
        this.clearSpinnerTimer();
        this.hideSpinner();
        this.hideError();
        this.clearSlots();

        if (this.didPushState) {
            this.didPushState = false;
            const hash = location.hash.match(HASH_PATTERN);
            if (hash) {
                history.back();
            }
        } else if (location.hash) {
            history.replaceState({}, '', location.pathname + location.search);
        }
    }

    open(index, { fromHistory = false } = {}) {
        if (index < 0 || index >= this.photos.length) return;

        const wasOpen = this.activeIndex !== null;
        this.activeIndex = index;
        const photo = this.photos[index];

        this.hideError();
        this.renderSlots();
        this.updateCounter();
        this.updateArrows();

        if (!wasOpen) {
            this.dialogTarget.showModal();
        }

        if (!fromHistory) {
            const hashValue = `#p=${photo.id}`;
            if (!wasOpen) {
                history.pushState({ lightbox: true, id: photo.id }, '', hashValue);
                this.didPushState = true;
            } else {
                history.replaceState({ lightbox: true, id: photo.id }, '', hashValue);
            }
        }

        this.preloadNeighbors(index);
    }

    goTo(index, { fromHistory = false } = {}) {
        if (this.activeIndex === null) {
            this.open(index, { fromHistory });
            return;
        }
        if (index === this.activeIndex || index < 0 || index >= this.photos.length) return;

        this.activeIndex = index;
        const photo = this.photos[index];

        this.hideError();
        this.renderSlots();
        this.updateCounter();
        this.updateArrows();

        if (!fromHistory) {
            history.replaceState({ lightbox: true, id: photo.id }, '', `#p=${photo.id}`);
        }

        this.preloadNeighbors(index);
    }

    preloadNeighbors(index) {
        const window = Math.max(1, this.preloadWindowValue);
        for (let offset = 1; offset <= window; offset += 1) {
            this.preload(index - offset);
            this.preload(index + offset);
        }
        if (index + window >= this.photos.length) {
            this.prefetchBeyondBoundary('next');
        }
        if (index - window < 0) {
            this.prefetchBeyondBoundary('prev');
        }
    }

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
        if (photo) {
            img.src = photo.previewUrl;
        } else {
            img.removeAttribute('src');
        }
    }

    clearSlots() {
        [this.slotPrevTarget, this.slotCurrTarget, this.slotNextTarget].forEach((img) => {
            img.removeAttribute('src');
            img.dataset.photoId = '';
        });
    }

    onImageLoad() {
        this.clearSpinnerTimer();
        this.hideSpinner();
        const current = this.currentPhoto();
        if (current) {
            this.preloadState.set(current.id, 'loaded');
        }
    }

    onImageError() {
        this.clearSpinnerTimer();
        this.hideSpinner();
        this.showError();
        const current = this.currentPhoto();
        if (current) {
            this.preloadState.set(current.id, 'error');
        }
    }

    preload(index) {
        if (index < 0 || index >= this.photos.length) return;
        const { id, previewUrl } = this.photos[index];
        const state = this.preloadState.get(id);
        if (state === 'loading' || state === 'loaded') return;
        this.preloadState.set(id, 'loading');
        const img = new Image();
        img.onload = () => this.preloadState.set(id, 'loaded');
        img.onerror = () => this.preloadState.set(id, 'error');
        img.src = previewUrl;
    }

    onPointerDown(event) {
        if (this.committing) return;
        if (this.reducedMotion) return;
        if (this.swipe) return;
        if (event.pointerType !== 'touch' && event.pointerType !== 'pen') return;
        if (event.button !== 0) return;
        this.swipe = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            samples: [{ x: event.clientX, t: event.timeStamp }],
            tracking: false,
            axisLocked: false,
            abandoned: false,
        };

        if (this.activeIndex !== null) {
            if (this.activeIndex === this.photos.length - 1) {
                this.fetchNeighborForActive('next').then((n) => this.onBoundaryNeighborResolved('next', n));
            }
            if (this.activeIndex === 0) {
                this.fetchNeighborForActive('prev').then((n) => this.onBoundaryNeighborResolved('prev', n));
            }
        }
    }

    onBoundaryNeighborResolved(direction, neighbor) {
        if (this.activeIndex === null) return;
        if (!neighbor) {
            this.updateArrows();
            return;
        }
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
                // Safari can throw on detached elements / already-captured pointers; safe to ignore.
                try { this.trackTarget.setPointerCapture(this.swipe.pointerId); } catch (_) {}
            } else {
                return;
            }
        }

        event.preventDefault();
        this.swipe.samples.push({ x: event.clientX, t: event.timeStamp });
        const cutoff = event.timeStamp - VELOCITY_WINDOW_MS;
        while (this.swipe.samples.length > 1 && this.swipe.samples[0].t < cutoff) {
            this.swipe.samples.shift();
        }
        this.applyDrag(event.clientX - this.swipe.startX);
    }

    applyDrag(dx) {
        const direction = dx < 0 ? 'next' : 'prev';
        const damped = this.hasInMemoryNeighbor(direction) ? dx : dx * RUBBER_BAND;
        this.trackTarget.style.transform = `translate3d(${damped}px, 0, 0)`;
    }

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

    onPointerUp(event) {
        if (!this.swipe || event.pointerId !== this.swipe.pointerId) return;
        const swipe = this.swipe;
        this.swipe = null;
        if (!swipe.tracking) return;

        const dx = event.clientX - swipe.startX;
        swipe.samples.push({ x: event.clientX, t: event.timeStamp });
        const cutoff = event.timeStamp - VELOCITY_WINDOW_MS;
        while (swipe.samples.length > 1 && swipe.samples[0].t < cutoff) {
            swipe.samples.shift();
        }
        const oldest = swipe.samples[0];
        const latest = swipe.samples[swipe.samples.length - 1];
        const dt = Math.max(1, latest.t - oldest.t);
        const v = (latest.x - oldest.x) / dt;
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

    onPointerCancel(event) {
        if (!this.swipe || event.pointerId !== this.swipe.pointerId) return;
        const wasTracking = this.swipe.tracking;
        this.swipe = null;
        if (wasTracking) this.animateTo(0, () => {});
    }

    hasInMemoryNeighbor(direction) {
        if (this.activeIndex === null) return false;
        return direction === 'next'
            ? this.photos[this.activeIndex + 1] !== undefined
            : this.photos[this.activeIndex - 1] !== undefined;
    }

    onPopState() {
        const match = location.hash.match(HASH_PATTERN);
        if (match) {
            const index = this.indexOfPhotoId(match[1]);
            if (index < 0) {
                if (this.activeIndex !== null) {
                    this.didPushState = false;
                    this.dialogTarget.close();
                }
                return;
            }
            if (this.activeIndex === null) {
                this.open(index, { fromHistory: true });
            } else if (this.activeIndex !== index) {
                this.goTo(index, { fromHistory: true });
            }
        } else if (this.activeIndex !== null) {
            this.didPushState = false;
            this.dialogTarget.close();
        }
    }

    openFromHashIfPresent() {
        const match = location.hash.match(HASH_PATTERN);
        if (!match) return;
        const index = this.indexOfPhotoId(match[1]);
        if (index < 0) {
            history.replaceState({}, '', location.pathname + location.search);
            return;
        }
        history.replaceState({ lightbox: true, id: match[1] }, '', `#p=${match[1]}`);
        this.open(index, { fromHistory: true });
        this.didPushState = false;
    }

    updateCounter() {
        if (!this.hasCounterTarget || this.activeIndex === null) return;
        const photo = this.currentPhoto();
        if (!photo) return;
        this.counterTarget.textContent = `${photo.rank} / ${this.totalReadyValue}`;
    }

    updateArrows() {
        if (this.activeIndex === null) return;
        const current = this.currentPhoto();
        const atStart = this.activeIndex === 0 && (current ? this.noNeighborFor.prev.has(current.id) : true);
        const atEnd = this.activeIndex === this.photos.length - 1
            && (current ? this.noNeighborFor.next.has(current.id) : true);
        if (this.hasPrevButtonTarget) {
            this.prevButtonTarget.hidden = atStart;
        }
        if (this.hasNextButtonTarget) {
            this.nextButtonTarget.hidden = atEnd;
        }
    }

    showSpinner() {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.remove('hidden');
        }
    }

    hideSpinner() {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.add('hidden');
        }
    }

    showError() {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.remove('hidden');
        }
    }

    hideError() {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.add('hidden');
        }
    }

    clearSpinnerTimer() {
        if (this.spinnerTimer) {
            clearTimeout(this.spinnerTimer);
            this.spinnerTimer = null;
        }
    }

    indexOfPhotoId(id) {
        if (id === undefined || id === null) return -1;
        const target = String(id);
        return this.photos.findIndex((p) => p.id === target);
    }

    currentPhoto() {
        if (this.activeIndex === null) return null;
        return this.photos[this.activeIndex];
    }
}
