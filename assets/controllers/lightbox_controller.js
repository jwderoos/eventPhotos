import { Controller } from '@hotwired/stimulus';

const SWIPE_THRESHOLD = 50;
const VERTICAL_RELEASE = 10;
const SPINNER_DELAY_MS = 200;
const HASH_PATTERN = /^#p=(\d+)$/;

export default class extends Controller {
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

    connect() {
        this.photos = this.triggerTargets.map((li) => ({
            id: li.dataset.photoId,
            previewUrl: li.dataset.previewUrl,
            element: li,
        }));

        if (this.photos.length === 0) {
            return;
        }

        this.activeIndex = null;
        this.preloadState = new Map();
        this.spinnerTimer = null;
        this.swipe = null;
        this.didPushState = false;

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

        this.imageTarget.addEventListener('load', this.onImageLoad);
        this.imageTarget.addEventListener('error', this.onImageError);
        this.imageTarget.addEventListener('pointerdown', this.onPointerDown);
        this.imageTarget.addEventListener('pointermove', this.onPointerMove);
        this.imageTarget.addEventListener('pointerup', this.onPointerUp);
        this.imageTarget.addEventListener('pointercancel', this.onPointerCancel);

        this.openFromHashIfPresent();
    }

    disconnect() {
        this.element.removeEventListener('click', this.onTriggerClick);
        if (this.hasDialogTarget) {
            this.dialogTarget.removeEventListener('keydown', this.onKeyDown);
            this.dialogTarget.removeEventListener('close', this.onDialogClose);
        }
        window.removeEventListener('popstate', this.onPopState);

        if (this.hasImageTarget) {
            this.imageTarget.removeEventListener('load', this.onImageLoad);
            this.imageTarget.removeEventListener('error', this.onImageError);
            this.imageTarget.removeEventListener('pointerdown', this.onPointerDown);
            this.imageTarget.removeEventListener('pointermove', this.onPointerMove);
            this.imageTarget.removeEventListener('pointerup', this.onPointerUp);
            this.imageTarget.removeEventListener('pointercancel', this.onPointerCancel);
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

    next() {
        if (this.activeIndex === null) return;
        if (this.activeIndex >= this.photos.length - 1) return;
        this.goTo(this.activeIndex + 1);
    }

    previous() {
        if (this.activeIndex === null) return;
        if (this.activeIndex <= 0) return;
        this.goTo(this.activeIndex - 1);
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
        this.swapImage(photo);
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

        this.preload(index - 1);
        this.preload(index + 1);
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
        this.swapImage(photo);
        this.updateCounter();
        this.updateArrows();

        if (!fromHistory) {
            history.replaceState({ lightbox: true, id: photo.id }, '', `#p=${photo.id}`);
        }

        this.preload(index - 1);
        this.preload(index + 1);
    }

    swapImage(photo) {
        this.clearSpinnerTimer();
        const state = this.preloadState.get(photo.id);

        if (state === 'loaded') {
            this.hideSpinner();
        } else {
            this.spinnerTimer = setTimeout(() => this.showSpinner(), SPINNER_DELAY_MS);
        }

        this.imageTarget.src = photo.previewUrl;
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
        if (event.pointerType === 'mouse' && event.button !== 0) return;
        this.swipe = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            currentX: event.clientX,
            abandoned: false,
        };
    }

    onPointerMove(event) {
        if (!this.swipe || event.pointerId !== this.swipe.pointerId || this.swipe.abandoned) return;
        const dx = event.clientX - this.swipe.startX;
        const dy = event.clientY - this.swipe.startY;
        if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > VERTICAL_RELEASE) {
            this.swipe.abandoned = true;
            return;
        }
        this.swipe.currentX = event.clientX;
    }

    onPointerUp(event) {
        if (!this.swipe || event.pointerId !== this.swipe.pointerId) return;
        const swipe = this.swipe;
        this.swipe = null;
        if (swipe.abandoned) return;
        const dx = event.clientX - swipe.startX;
        if (Math.abs(dx) < SWIPE_THRESHOLD) return;
        if (dx < 0) {
            this.next();
        } else {
            this.previous();
        }
    }

    onPointerCancel() {
        this.swipe = null;
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
        this.counterTarget.textContent = `${this.activeIndex + 1} / ${this.photos.length}`;
    }

    updateArrows() {
        if (this.activeIndex === null) return;
        const atStart = this.activeIndex === 0;
        const atEnd = this.activeIndex === this.photos.length - 1;
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
