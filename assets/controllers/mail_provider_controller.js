import { Controller } from '@hotwired/stimulus';

// Toggles the visible mail-provider input block and mirrors the Gmail address
// into the from-address until the user edits the from-address themselves.
export default class extends Controller {
    static targets = ['custom', 'gmail', 'gmailEmail', 'fromAddr'];

    connect() {
        // A pre-populated from-address (e.g. editing an existing config) is
        // user-owned, so never overwrite it.
        this.fromAddrDirty = this.hasFromAddrTarget && this.fromAddrTarget.value !== '';
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

    fromAddrEdited() {
        this.fromAddrDirty = true;
    }

    prefillFromAddr() {
        if (this.fromAddrDirty || !this.hasFromAddrTarget || !this.hasGmailEmailTarget) {
            return;
        }

        // A programmatic value assignment does not fire an `input` event, so
        // this mirror never marks the field dirty against itself.
        this.fromAddrTarget.value = this.gmailEmailTarget.value;
    }
}
