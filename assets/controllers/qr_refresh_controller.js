import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['qr', 'updated', 'photosUrl'];

    static values = {
        endpoint: String,
        timezone: String,
        state: String,
        intervalMs: { type: Number, default: 60000 },
    };

    connect() {
        if (this.stateValue === 'post') {
            return;
        }
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

            const serverState = response.headers.get('X-Display-State');

            if (serverState && serverState !== this.stateValue) {
                window.location.reload();
                return;
            }

            if (!response.ok) {
                return;
            }

            const svg = await response.text();
            if (this.hasQrTarget) {
                this.qrTarget.innerHTML = svg;
            }

            const photosUrl = response.headers.get('X-Photos-Url');
            if (photosUrl && this.hasPhotosUrlTarget) {
                this.photosUrlTarget.href = photosUrl;
                this.photosUrlTarget.textContent = photosUrl;
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
