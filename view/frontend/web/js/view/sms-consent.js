/**
 * Luma checkout: the text reminder checkbox right under the shipping phone (a jsLayout child, placed by
 * Model\Checkout\SmsConsentLayout). The number is the shipping phone - the address form's, or the selected saved
 * address's - in E.164 with the address's country (sms-phone-core); "Use a different number" takes another one. The
 * checkbox is Notification's address consent widget: it records the consent for that number, withdraws it on uncheck,
 * moves it when the number changes and re-checks the box when a withdrawal fails. Once a consent is recorded
 * ("ckAddressConsent:recorded"), the cart is signed up for the text reminder. The notification type is
 * "subscriptionType": Magento's layout builder takes a component's "type" for itself.
 */
define([
    'jquery',
    'ko',
    'uiComponent',
    'uiRegistry',
    'Magento_Checkout/js/model/quote',
    'CommerceKings_AbandonedCartSms/js/sms-phone-core',
    'CommerceKings_Notification/js/address-consent'
], function ($, ko, Component, registry, quote, core) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'CommerceKings_AbandonedCartSms/sms-consent',
            placement: 'address-form',
            subscriptionType: '',
            country: '',
            urls: {guest: '', mine: ''},
            consent: {wording: '', wordingHash: '', urls: {guest: '', mine: ''}},
            // fixed ids in the template: set before the widget reads them (the wrapper's afterRender runs first)
            inputId: 'ck-sms-phone-number',
            boxId: 'ck-address-consent-cart_reminder_sms'
        },

        initialize: function () {
            var self = this;

            this._super();
            this.addressPhone = ko.observable('');
            this.addressCountry = ko.observable('');
            this.useCustom = ko.observable(false);
            this.custom = ko.observable('');
            this.number = ko.pureComputed(function () {
                return core.numberFor({
                    addressPhone: self.addressPhone(),
                    addressCountry: self.addressCountry(),
                    useCustom: self.useCustom(),
                    custom: self.custom(),
                    defaultCountry: self.country
                }).number;
            });
            this.shown = ko.pureComputed(function () {
                return core.display(self.number());
            });
            this.customInvalid = ko.pureComputed(function () {
                return self.useCustom() && String(self.custom() || '').trim() !== ''
                    && core.toE164(self.custom(), self.addressCountry() || self.country) === '';
            });
            this.number.subscribe(function (number) {
                self.numberChanged(number);
            });
            this.watchAddress();
            return this;
        },

        /** The shipping phone: the address form's (guests, no saved address) or the selected saved address's. */
        watchAddress: function () {
            var self = this;

            if (this.placement === 'address-list') {
                var take = function (address) {
                    self.addressPhone(address && address.telephone || '');
                    self.addressCountry(address && address.countryId || '');
                };

                take(quote.shippingAddress());
                quote.shippingAddress.subscribe(take);
                return;
            }
            registry.async('checkoutProvider')(function (provider) {
                var read = function () {
                    self.addressPhone(provider.get('shippingAddress.telephone') || '');
                    self.addressCountry(provider.get('shippingAddress.country_id') || '');
                };

                read();
                provider.on('shippingAddress', read);
            });
        },

        toggleCustom: function () {
            this.useCustom(!this.useCustom());
        },

        /** The consent widget on the rendered block (afterRender). */
        initConsent: function (element) {
            var self = this;

            this.root = $(element);
            this.root.ckAddressConsent({
                type: this.subscriptionType,
                wordingHash: this.consent.wordingHash,
                urls: this.consent.urls,
                phone: '#' + this.inputId
            });
            this.root.on('ckAddressConsent:recorded', function (event, consent) {
                self.subscribeCart(consent && consent.recipient);
            });
        },

        /** The widget reads the hidden field: a new number moves the consent; no number withdraws it. */
        numberChanged: function (number) {
            var box;

            if (!this.root) {
                return;
            }
            this.root.find('#' + this.inputId).val(number).trigger('change');
            box = this.root.find('#' + this.boxId);
            if (number === '' && box.prop('checked')) {
                box.prop('checked', false).trigger('change');
            }
        },

        subscribeCart: function (recipient) {
            var config = window.checkoutConfig || {},
                reference = (config.quoteData || {}).entity_id;

            if (!recipient || !reference) {
                return;
            }
            core.subscribe({
                url: config.isCustomerLoggedIn ? this.urls.mine : this.urls.guest,
                type: this.subscriptionType,
                reference: reference,
                recipient: recipient
            }).catch(function () {
                // a refused or failed sign-up only means no text reminder; checkout goes on
            });
        }
    });
});
