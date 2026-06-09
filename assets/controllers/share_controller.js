import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
        title: String,
        text: String,
    };

    async share(event) {
        event.preventDefault();

        const payload = {
            title: this.titleValue,
            text:  this.textValue,
            url:   this.urlValue,
        };

        if (navigator.share) {
            try {
                await navigator.share(payload);
                return;
            } catch (err) {
                if (err.name === 'AbortError') {
                    return;
                }
            }
        }

        await this.copyFallback(payload.url);
    }

    async copyFallback(url) {
        try {
            await navigator.clipboard.writeText(url);
            this.flash('Link copied');
        } catch (_err) {
            window.prompt('Copy this link', url);
        }
    }

    flash(message) {
        const el = document.createElement('div');
        el.textContent = message;
        el.setAttribute('role', 'status');
        el.style.cssText = 'position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);background:#222;color:#fff;padding:0.5rem 1rem;border-radius:0.25rem;';
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 2000);
    }
}
