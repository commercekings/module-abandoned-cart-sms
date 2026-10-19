# Abandoned Cart SMS<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-abandoned-cart-sms/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-abandoned-cart-sms/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · Luma checkout · PHP 8.1–8.5 · any SMS provider module for CommerceKings Notification

[![License](https://badgen.net/github/license/commercekings/module-abandoned-cart-sms)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-abandoned-cart-sms)](https://github.com/commercekings/module-abandoned-cart-sms/releases) [![Checks](https://github.com/commercekings/module-abandoned-cart-sms/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-abandoned-cart-sms/actions/workflows/checks.yml)

**A text for every abandoned cart, next to the email.** The shopper ticks the box under their shipping phone at
checkout; when the cart is left, one text brings them back.

The reminder email is built into Abandoned Cart; this module adds the text.
**Works together with:**

- [module-abandoned-cart](https://github.com/commercekings/module-abandoned-cart) – the base: the email reminder, its finder, queue and retries.
- [module-notification-twilio](https://github.com/commercekings/module-notification-twilio) – delivers the texts through Twilio,
- [module-notification-infobip](https://github.com/commercekings/module-notification-infobip) – or through Infobip; pick one.
- [module-rate-limit](https://github.com/commercekings/module-rate-limit) – recommended: limits the public sign-up endpoints (Magento 2.4.7+).

- **Consent per number.** A checkbox next to the number, stored with the exact wording, the time and a hashed IP –
  the evidence US carriers (10DLC) and the GDPR ask for. A STOP reply ends it.
- **Respectful by design.** Quiet hours (on by default; also in the recipient's time zone, with a provider that knows
  it), the recipient cooldown, one text per cart, and "Reply STOP to stop" in every text.
- **Built on Abandoned Cart.** The same finder, queue, retries and hourly limit as the reminder email; the email
  reminder is unaffected.
- **Any provider.** The text goes out through the SMS provider selected in Notifications; this module never
  talks to one itself.

## Installation

```sh
composer require commercekings/module-abandoned-cart-sms commercekings/module-notification-twilio
# or, for Infobip: composer require commercekings/module-abandoned-cart-sms commercekings/module-notification-infobip
bin/magento setup:upgrade
```

Install one provider module per channel. Any module that provides an `sms` provider for CommerceKings Notification
works. Without one, nothing changes at checkout.

Then:

1. **Your provider's group** (e.g. *Notifications > Twilio* or *Notifications > Infobip*): credentials and sender. With
   several SMS providers, select one under *Notifications > Channels and providers* – but install only one per channel.
2. **Notifications > Consent for an address > Checkbox text: text messages:** the checkbox wording (a default is
   set). Name your store, what you send, how often and how to stop.
3. **Abandoned Cart > Text message reminders:** *Enabled* Yes, the delay, the hourly limit and the text.

The checkbox appears at checkout only when all three are in place.

## Settings

**Stores > Configuration > CommerceKings > Abandoned Cart > Text message reminders**:

| Setting | Default |
|---|---|
| Enabled (store view) | No |
| Send after (minutes) | 60 |
| Maximum texts per hour (global) | 0 – no limit; set your provider's |
| Text (store view) | `{{store}}: you left something in your cart. Complete your order: {{link}} Reply STOP to stop` |

Placeholders: `{{store}}` (Store Information name, else the store's name), `{{name}}` (the first name, may be empty),
`{{product}}` (the first product, at most 40 characters), `{{total}}`, `{{link}}` (the cart page). One text holds 160
characters, or 70 when it has an emoji or a character outside the basic set.

Quiet hours, the recipient cooldown and STOP replies are Notification's settings and apply to texts too.

## At checkout

Right under the shipping phone: one checkbox, labelled with the checkbox text (*Notifications > Consent for an address*)
with the number in place of `{{number}}` – by default *Yes, text me offers and reminders at +1 202 555 0123. Msg
frequency varies; msg & data rates may apply. Reply STOP to stop.* The configured text is what is stored as the
consent's evidence, with the number. The number is the shipping phone (the address form's, or the selected saved
address's for a signed-in customer), read with the address's country, so a national number (`060 123 4567`) works; it is
stored in E.164. A phone that cannot take texts (empty, shorter than a mobile number of its country, not a number) gets
no checkbox. Ticking the box records the consent for that number and signs the cart up; unticking withdraws it and ends
the sign-up. The number is taken when the shopper leaves the phone field, changes the country or picks another saved
address – never per keystroke. If it changes while the box is ticked, the consent is withdrawn and the box unticked: a
new number gets a consent only when the shopper ticks again.

## Before going live

- **US:** register your 10DLC brand and campaign (or toll-free verification) with your provider before sending; the
  checkbox text is the opt-in language of your campaign.
- **Quiet hours:** on by default for SMS (Notifications > Sending rules > Quiet hours, 20:00–08:00) – check the window;
  US state laws such as Florida's FTSA go by the recipient's local time – use a provider module with a
  recipient-time-zone zone provider.
- **Opt-in confirmation:** some rules want a confirmation text after the first sign-up of a number; the Twilio and
  Infobip modules send one (on by default; in their settings).
- **Protect against SMS pumping.** The public sign-up endpoints can be abused to trigger paid texts (e.g. opt-in
  confirmations to premium-rate numbers) – plan for it: limit them with [module-rate-limit](https://github.com/commercekings/module-rate-limit) and turn on your
  provider's fraud protection (Twilio: Geo Permissions and SMS Pumping Protection; Infobip: check your account for
  destination restrictions and fraud protection).
- Send a test order with your own number and check the text, the link and a STOP reply.

## Limitations

- The link opens the cart page (the shopper's session or account cart): a phone cannot prove it is the browser the
  cart was left in, so there is no cross-device restore for texts.
- Luma checkout only (also in Hyvä themes using Hyvä's Luma checkout fallback); Hyvä Checkout and headless are not
  supported. The checkbox is a component of Luma's shipping step; a custom checkout can build on the framework-free
  `CommerceKings_AbandonedCartSms/js/sms-phone-core` and Notification's REST routes.
- National numbers are converted with the store's default country and a calling-code table, not a phone library;
  shoppers abroad type the `+` form.
- **Live testing:** text reminders are tested live with Infobip; Twilio only against a simulated API. See each provider's
  README.

## More

[How it works](docs/how-it-works.md) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md) ·
[Abandoned Cart: other channels](https://github.com/commercekings/module-abandoned-cart/blob/main/docs/extending.md)

## Disclaimer

These modules help you ask for, record and respect your shoppers' choices – they don't make your store compliant on
their own. You are responsible for your store's privacy: what you collect, the wording you show, your privacy policy
and whether your setup meets the laws that apply to you (e.g. GDPR, ePrivacy, TCPA, SMS marketing rules). Get legal
advice if you are unsure.

---

**Need it installed, set up or customized?** **[Get in touch →](https://commercekings.net)**
