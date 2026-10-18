# Changelog

## Unreleased (0.1.0)

First release. Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`), `commercekings/module-abandoned-cart`
with reminder types and `commercekings/module-notification`; PHP 8.1–8.5.

- **Text message reminders:** the reminder type `cart_reminder_sms` (`Model\SmsReminder`) next to Abandoned Cart's
  email – one text per cart through the SMS provider selected in Notifications, with Abandoned Cart's finder, queue,
  retries, policy and its own hourly limit and send history. Built on `@api` and di.xml only.
- **Consent per number:** Notification's address consent (a checkbox stored with the wording, time, hashed IP and the
  number as a keyed hash) for this type; a STOP reply withdraws it.
- **Checkout (Luma):** *Mobile number for a cart reminder* and the checkbox below the checkout, only when the
  reminders are on, an SMS provider is installed and the store view has a checkbox text. National numbers get the
  store's default country code; the number is stored in E.164. The framework-free `js/sms-phone-core` is for other
  storefronts.
- **Settings** (*Abandoned Cart > Text message reminders*): enabled, delay, texts per hour and the text per store view
  with `{{store}}`, `{{name}}`, `{{product}}`, `{{total}}` and `{{link}}` (the cart page); the default names the store
  and ends with "Reply STOP to stop".
