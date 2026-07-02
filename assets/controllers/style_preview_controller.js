import { Controller } from '@hotwired/stimulus';

// Mirrors App\Service\Style\ResolvedStyle: luminance-based button contrast and
// the accent-derived radial glow. Kept in sync with the PHP derivation.
export default class extends Controller {
    static targets = ['card', 'input', 'toggle', 'glowMode'];
    static values = {
        inheritedFont:       String,
        inheritedBackground: String,
        inheritedButton:     String,
        inheritedGlow:       String,
    };

    connect() {
        this.render();
    }

    // Drives the preview the same way the public page does (App\Controller\Public
    // \EventController + public/_base.html.twig): set CSS custom properties on the
    // card and let DaisyUI's semantic classes consume them. The card carries
    // data-theme="silk" so an uncustomised preview shows the exact public defaults
    // (null-omit: an unset field emits no override, so silk's value stands).
    render() {
        if (!this.hasCardTarget) {
            return;
        }
        const card = this.cardTarget;

        const font   = this.effective('fontColor',       this.inheritedFontValue);
        const bg     = this.effective('backgroundColor', this.inheritedBackgroundValue);
        const button = this.effective('buttonColor',     this.inheritedButtonValue);

        this.setVar(card, '--color-base-content', font);
        this.setVar(card, '--color-base-100', bg);
        this.setVar(card, '--color-primary', button);
        this.setVar(card, '--color-primary-content', button ? this.contrast(button) : null);

        if (this.effectiveGlow() && button) {
            const [r, g, b] = this.hexToRgb(button);
            card.style.background = `radial-gradient(circle, rgba(${r}, ${g}, ${b}, 0.4), ${bg || '#FFFFFF'})`;
        } else {
            card.style.background = '';
        }
    }

    setVar(el, name, value) {
        if (value) {
            el.style.setProperty(name, value);
        } else {
            el.style.removeProperty(name);
        }
    }

    effective(field, inherited) {
        const toggle = this.toggleTargets.find((t) => t.dataset.field === field);
        const input  = this.inputTargets.find((t)  => t.dataset.field === field);
        if (toggle && toggle.checked && input) {
            return input.value;
        }
        return inherited || null;
    }

    // Mirrors StyleResolver: 'on'/'off' win at this tier; 'inherit' falls back
    // to the resolved parent glow value.
    effectiveGlow() {
        if (!this.hasGlowModeTarget) {
            return false;
        }
        const mode = this.glowModeTarget.value;
        if (mode === 'on') {
            return true;
        }
        if (mode === 'off') {
            return false;
        }
        return this.inheritedGlowValue === '1';
    }

    hexToRgb(hex) {
        return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    }

    contrast(hex) {
        const [r, g, b] = this.hexToRgb(hex);
        const lum = 0.2126 * (r / 255) + 0.7152 * (g / 255) + 0.0722 * (b / 255);
        return lum > 0.5 ? '#000000' : '#FFFFFF';
    }
}
