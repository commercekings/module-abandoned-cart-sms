# Changelog

## 0.1.0 - 2026-10-19

First release. Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`), `commercekings/module-abandoned-cart`
with reminder types and `commercekings/module-notification`; PHP 8.1–8.5.

- **Text message reminders:** the reminder type `cart_reminder_sms` (`Model\SmsReminder`) next to Abandoned Cart's
  email – one text per cart through the SMS provider selected in Notifications, with Abandoned Cart's finder, queue,
  retries, policy and its own hourly limit and send history. Built on `@api` and di.xml only.
- **Consent per number:** Notification's address consent (a checkbox stored with the wording, time, hashed IP and the
  number as a keyed hash) for this type; a STOP reply withdraws it.
- **Checkout (Luma):** the checkbox right under the shipping phone (a jsLayout child; after the saved addresses for a
  customer who has them), for the shipping phone in E.164 with the address's country, labelled with the consent wording
  and the number in it (Notification's `{{number}}`). The number is taken when the phone field is left, never per
  keystroke; a number shorter than a mobile number of its country gets no checkbox; a changed number unticks the box and
  withdraws the consent – a new number is recorded only on a new tick. Only when the reminders are on, an SMS provider
  is installed and the store view has a checkbox text. A custom checkout can build on the framework-free
  `js/sms-phone-core`.
- **Settings** (*Abandoned Cart > Text message reminders*): enabled, delay, texts per hour and the text per store view
  with `{{store}}`, `{{name}}`, `{{product}}`, `{{total}}` and `{{link}}` (the cart page); the default names the store
  and ends with "Reply STOP to stop".
