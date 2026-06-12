import { Controller } from '@hotwired/stimulus';

// Mirrors the server-side normalisation in EventType::normalizeTimeString().
// 1-2 digits  -> HH:00
// 3-4 digits  -> HHMM (left-padded to 4)
// anything else (incl. already-formatted HH:mm) passes through; the regex
// constraint on the server is the source of truth for what counts as valid.
export default class extends Controller {
    format(event) {
        const el = event.target;
        if (!(el instanceof HTMLInputElement)) {
            return;
        }
        const normalised = this.normalise(el.value);
        if (normalised !== el.value) {
            el.value = normalised;
        }
    }

    normalise(raw) {
        const trimmed = raw.trim();

        if (/^\d{1,2}$/.test(trimmed)) {
            return trimmed.padStart(2, '0') + ':00';
        }

        if (/^\d{3,4}$/.test(trimmed)) {
            const padded = trimmed.padStart(4, '0');
            return padded.slice(0, 2) + ':' + padded.slice(2, 4);
        }

        return raw;
    }
}
