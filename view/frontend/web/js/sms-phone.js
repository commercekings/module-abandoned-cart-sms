/**
 * Luma checkout: the phone field for text reminders (templates/checkout/sms.phtml). The number the shopper types goes
 * into the hidden field in E.164 (sms-phone-core) - that field is Notification's address consent checkbox's phone -
 * and once the checkbox's consent is recorded ("ckAddressConsent:recorded"), the cart is signed up for the text
 * reminder. Unchecking withdraws the consent, which ends the sign-up on the server.
 */
define([
    'jquery',
    'CommerceKings_AbandonedCartSms/js/sms-phone-core',
    'jquery-ui-modules/widget'
], function ($, core) {
    'use strict';

    $.widget('commercekings.ckSmsPhone', {
        options: {type: '', country: '', urls: {guest: '', mine: ''}},

        _create: function () {
            var self = this;

            this.input = this.element.find('.ck-sms-phone-input');
            this.number = this.element.find('.ck-sms-phone-number');
            this.error = this.element.find('.ck-sms-phone-error');
            this.input.on('change blur', function () { self._update(); });
            this.element.on('ckAddressConsent:recorded', function (event, consent) {
                self._subscribe(consent && consent.recipient);
            });
        },

        _config: function () {
            return window.checkoutConfig || {};
        },

        /** The number in E.164 for the checkbox; a number that cannot be one shows the hint and clears it. */
        _update: function () {
            var typed = String(this.input.val() || '').trim(),
                number = typed === '' ? '' : core.toE164(typed, this.options.country);

            this.error.toggle(typed !== '' && number === '');
            if (number !== this.number.val()) {
                this.number.val(number).trigger('change');
            }
        },

        _subscribe: function (recipient) {
            var config = this._config(),
                reference = (config.quoteData || {}).entity_id,
                mine = !!config.isCustomerLoggedIn;

            if (!recipient || !reference) {
                return;
            }
            core.subscribe({
                url: mine ? this.options.urls.mine : this.options.urls.guest,
                type: this.options.type,
                reference: reference,
                recipient: recipient
            }).catch(function () {
                // a refused or failed sign-up only means no text reminder; checkout goes on
            });
        }
    });

    return $.commercekings.ckSmsPhone;
});
