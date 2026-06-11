import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['dialog', 'input', 'submit'];
    static values  = { expected: String };

    open() {
        this.inputTarget.value = '';
        this.submitTarget.disabled = true;
        this.dialogTarget.showModal();
    }

    check() {
        this.submitTarget.disabled = this.inputTarget.value !== this.expectedValue;
    }
}
