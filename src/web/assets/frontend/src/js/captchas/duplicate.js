import { FormieCaptchaProvider } from './captcha-provider';
import { eventKey } from '../utils/utils';

export class FormieDuplicateCaptcha extends FormieCaptchaProvider {
    constructor(settings = {}) {
        super(settings);

        this.$form = settings.$form;
        this.form = this.$form.form;
        this.sessionKey = settings.sessionKey;
        this.value = settings.value;

        this.$placeholder = this.$form.querySelector('[data-duplicate-captcha-placeholder]');

        if (!this.$placeholder) {
            console.error('Unable to find Duplicate Captcha placeholder for [data-duplicate-captcha-placeholder]');

            return;
        }

        this.createInput();

        this.form.addEventListener(this.$form, eventKey('onAfterFormieSubmit', 'DuplicateCaptcha'), this.onAfterSubmit.bind(this));
    }

    createInput() {
        // We need to handle re-initializing, so always empty the placeholder to start fresh to prevent duplicate captchas
        this.$placeholder.innerHTML = '';

        const $input = document.createElement('input');
        $input.setAttribute('type', 'hidden');
        $input.setAttribute('name', this.sessionKey);
        $input.value = this.value;

        this.$placeholder.appendChild($input);
    }

    onAfterSubmit(e) {
        // Ensure that once completed, we re-fetch the captcha value, which will have expired
        Formie.refreshFormTokens(this.form);
    }
}

window.FormieDuplicateCaptcha = FormieDuplicateCaptcha;
