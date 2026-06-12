import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['qr', 'updated'];

    static values = {
        endpoint: String,
        timezone: String,
        intervalMs: { type: Number, default: 60000 },
    };

    connect() {
        this.boundRefresh = this.refresh.bind(this);
        this.timer = setInterval(this.boundRefresh, this.intervalMsValue);
    }

    disconnect() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    async refresh() {
        try {
            const response = await fetch(this.endpointValue, {
                headers: { Accept: 'image/svg+xml' },
                cache: 'no-store',
            });
            if (!response.ok) {
                return;
            }
            const svg = await response.text();
            if (this.hasQrTarget) {
                this.qrTarget.innerHTML = svg;
            }
            if (this.hasUpdatedTarget) {
                this.updateTimestamp();
            }
        } catch (e) {
            // Silent: a failed refresh just means the existing QR stays on screen.
            // The next interval tick will try again.
        }
    }

    updateTimestamp() {
        const now = new Date();
        const formatter = new Intl.DateTimeFormat('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
            timeZone: this.timezoneValue || undefined,
        });
        this.updatedTarget.textContent = formatter.format(now);
        this.updatedTarget.setAttribute('datetime', now.toISOString());
    }
}
