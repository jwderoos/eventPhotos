import { Controller } from '@hotwired/stimulus';

// Toggles the visible mail-provider input block and pre-fills the from-address
// from the Gmail address (only when from-address is still empty).
export default class extends Controller {
    static targets = ['custom', 'gmail', 'gmailEmail', 'fromAddr'];

    connect() {
        this.toggle();
    }

    providerChanged(event) {
        this.provider = event.target.value;
        this.toggle();
    }

    toggle() {
        const provider = this.provider ?? this.selectedProvider();
        const isGmail = provider === 'gmail';
        this.customTarget.hidden = isGmail;
        this.gmailTarget.hidden = !isGmail;
    }

    selectedProvider() {
        const checked = this.element.querySelector('input[name$="[provider]"]:checked');
        return checked ? checked.value : 'custom';
    }

    prefillFromAddr() {
        if (this.hasFromAddrTarget && this.hasGmailEmailTarget && this.fromAddrTarget.value === '') {
            this.fromAddrTarget.value = this.gmailEmailTarget.value;
        }
    }
}
