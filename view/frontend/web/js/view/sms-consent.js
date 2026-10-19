/**
 * Luma checkout: the text reminder checkbox right under the shipping phone (a jsLayout child, placed by
 * Model\Checkout\SmsConsentLayout). Its label is the configured consent wording with the number in place of
 * {{number}} ("Yes, text me offers and reminders at +1 202 555 0123. …", Notification's renderWording()); the
 * wording itself is the evidence. The number is the shipping phone - the address form's, or the selected saved
 * address's - in E.164 with the address's country (sms-phone-core); a phone that cannot take texts gets no checkbox.
 * The number is taken when the shopper has finished typing it (the phone field loses focus), on a country change and
 * when another saved address is selected - never per keystroke. The checkbox is Notification's address consent
 * widget: it records the consent for that number when ticked, withdraws it on untick and re-checks the box when a
 * withdrawal fails. A ticked box whose number changes is unticked (the consent withdrawn): a new number is only
 * recorded when the shopper ticks again. Once a consent is recorded ("ckAddressConsent:recorded"), the cart is
 * signed up for the text reminder. The notification type is "subscriptionType": Magento's layout builder takes a
 * component's "type" for itself.
 */
define([
    'jquery',
    'ko',
    'uiComponent',
    'uiRegistry',
    'Magento_Checkout/js/model/quote',
    'CommerceKings_AbandonedCartSms/js/sms-phone-core',
    'CommerceKings_Notification/js/address-consent-core',
    'CommerceKings_Notification/js/address-consent'
], function ($, ko, Component, registry, quote, core, consentCore) {
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
            this.phoneFocused = false;
            // the shipping phone as it is now, possibly still being typed
            this.typed = ko.pureComputed(function () {
                return core.numberFor({
                    addressPhone: self.addressPhone(),
                    addressCountry: self.addressCountry(),
                    defaultCountry: self.country
                });
            });
            // the number the checkbox is for: the phone once typed (see take())
            this.number = ko.observable('');
            // the configured wording with the number in place of {{number}} - the label; the wording is the evidence
            this.label = ko.pureComputed(function () {
                return consentCore.renderWording(self.consent.wording, self.number(), '');
            });
            this.number.subscribe(function (number) {
                self.numberChanged(number);
            });
            this.watchAddress();
            return this;
        },

        /** The shipping phone and country changed: the number is taken, unless only a phone being typed changed. */
        address: function (phone, country) {
            var countryChanged = country !== this.addressCountry();

            this.addressCountry(country);
            this.addressPhone(phone);
            if (countryChanged || !this.phoneFocused) {
                this.take();
            }
        },

        take: function () {
            this.number(this.typed());
        },

        /** The shipping phone: the address form's (guests, no saved address) or the selected saved address's. */
        watchAddress: function () {
            var self = this;

            if (this.placement === 'address-list') {
                var take = function (address) {
                    self.address(address && address.telephone || '', address && address.countryId || '');
                };

                take(quote.shippingAddress());
                quote.shippingAddress.subscribe(take);
                return;
            }
            registry.async('checkoutProvider')(function (provider) {
                var read = function () {
                    self.address(provider.get('shippingAddress.telephone') || '',
                        provider.get('shippingAddress.country_id') || '');
                };

                read();
                provider.on('shippingAddress', read);
            });
            // the address form's phone field (a sibling): its number is taken when it loses focus
            registry.async(this.parentName + '.telephone')(function (field) {
                if (!field || !ko.isObservable(field.focused)) {
                    return;
                }
                self.phoneFocused = !!field.focused();
                field.focused.subscribe(function (focused) {
                    self.phoneFocused = !!focused;
                    if (!focused) {
                        self.take();
                    }
                });
            });
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

        /**
         * Another number, or none: a ticked box is unticked first, so the widget withdraws the consent of the previous
         * number - the consent was given for that one. The new number is recorded only when the shopper ticks again.
         */
        numberChanged: function (number) {
            var box;

            if (!this.root) {
                return;
            }
            box = this.root.find('#' + this.boxId);
            if (box.prop('checked')) {
                box.prop('checked', false).trigger('change');
            }
            this.root.find('#' + this.inputId).val(number).trigger('change');
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
