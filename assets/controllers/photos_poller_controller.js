import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        src: String,
    };

    connect() {
        this.boundOnFrameLoad = this.onFrameLoad.bind(this);
        this.element.addEventListener('turbo:frame-load', this.boundOnFrameLoad);
        this.element.addEventListener('photos:row-added', this.boundOnFrameLoad);
        this.scheduleIfNeeded();
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-load', this.boundOnFrameLoad);
        this.element.removeEventListener('photos:row-added', this.boundOnFrameLoad);
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    onFrameLoad() {
        this.scheduleIfNeeded();
    }

    scheduleIfNeeded() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
        const stillWorking = this.element.querySelector(
            '[data-status="pending"], [data-tagging="pending"], [data-processing-incomplete]'
        ) !== null;
        if (!stillWorking) {
            return;
        }
        this.timer = setTimeout(() => this.poll(), 5000);
    }

    poll() {
        const base = this.srcValue || this.element.getAttribute('src') || '';
        if (!base) {
            return;
        }
        const [path, query = ''] = base.split('?');
        const params = new URLSearchParams(query);
        params.set('_', String(Date.now()));
        this.element.setAttribute('src', `${path}?${params.toString()}`);
    }
}
