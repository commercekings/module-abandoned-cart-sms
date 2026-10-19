# How it works

A reminder type for `commercekings/module-abandoned-cart` (its [other channels guide](https://github.com/commercekings/module-abandoned-cart/blob/main/docs/extending.md)),
built on `@api` and di.xml only. Start with the [README](../README.md).

## Parts

- **`Model\SmsReminder`** (`ReminderTypeInterface`, code `cart_reminder_sms`, channel `sms`): its settings
  (`Model\Config`, `commercekings_abandoned_cart/sms/*`), its consent (a virtual type of Notification's `AddressConsent`
  for this code), the reminder policy (the shared preference: one per cart for this type; the email reminder has its
  own), the recipient (the number of the cart's `cart_reminder_sms` subscription, in E.164 through Notification's
  `PhoneNormalizer` – a contact is kept as first entered, and the finder's cooldown check needs the sender's form) and
  the text (the configured text with `CartDataInterface`'s facts and `RestoreLinkInterface`'s link – the cart page). Its
  delivery is a stoppable text of this type.
- **di.xml:** the type in Abandoned Cart's `ReminderTypePool`; virtual types of Abandoned Cart's `CartReminder` and
  `CartSubscription` with it as `type`, registered in Notification's `TypePool` and `SubscriptionTypePool`; the cart
  data and the restore link through `\Proxy` (both are built from Notification's subscription type pool, which
  builds this type).
- **Checkout** (`Model\Checkout\SmsConsentLayout`, a layout processor of Magento's checkout in `etc/frontend/di.xml`;
  `js/view/sms-consent` with `template/sms-consent.html`; `ViewModel\Checkout`): added only when the type is on in the
  store, an `sms` provider is installed and the store view has a checkbox text. It is a jsLayout child right after the
  shipping address's `telephone` (its `sortOrder` + 1); for a signed-in customer with saved addresses – the form is then
  only the "New Address" pop-up – it goes after the address list (`address-list-additional-addresses`). The number is
  the shipping phone (the form's `checkoutProvider` data, or `quote.shippingAddress()` for a saved address) in E.164
  with the address's country (`js/sms-phone-core`: `numberFor()`), taken when the phone field loses focus (its
  `focused`), on a country change and when another saved address is selected – never per keystroke; the label is the
  configured consent wording – the evidence – with the number in place of `{{number}}` (Notification's
  `address-consent-core`: `renderWording()`); a phone that cannot take texts gets no checkbox. It goes into a hidden
  field, the phone of Notification's address consent widget (`CommerceKings_Notification/js/address-consent`): checking
  records the consent, unchecking withdraws it, a failed withdrawal checks the box again. A changed number, or none,
  unchecks the box first, so the consent of the previous number is withdrawn and the new number is only recorded on a
  new tick. When the consent is recorded (`ckAddressConsent:recorded`), the cart is signed up: guests with the masked
  cart id, customers with the quote id and `/mine`.

## Flow

1. Checkout: number + checkbox → Notification records the address consent (wording, time, hashed IP, the number as a
   keyed hash) → `POST /V1/ck-notification/subscriptions` (type `cart_reminder_sms`). Notification checks the number
   (E.164), the stop list and the consent before it stores the subscription.
2. Every minute Abandoned Cart's finder queues the due carts of every reminder type – this one with its own delay,
   policy, hourly limit and send history (`ck_notification_delivery_state`, type `cart_reminder_sms`); the number's
   cooldown is checked on the sms channel.
3. The reminder claims the cart, Notification's sender applies the stop list, quiet hours and the cooldown, and the
   selected SMS provider sends the text. A failed send is retried like an email; a STOP reply stops the number
   (Notification) and the next reminder for it is dropped.

The email reminder is unaffected: its own history, its own subscription (the email), its own settings. A cart can
get both, each once.

## Tests

- `Test/Unit`: the type (identity, settings, recipient, delivery, text and placeholders), the settings, the checkout
  view model and layout processor (under the telephone, after saved addresses, nothing when unavailable), and the wiring
  – every Abandoned Cart and Notification class named in PHP or di.xml is `@api`, and the cart data and restore link
  come through proxies.
- `Test/JavaScript`: E.164 conversion (international, national with trunk prefix, a "(0)" after the country code, a
  country code without + or 00, North America, Italy, mobile lengths per country so partial numbers fail, refusals), the
  number from the shipping phone (`numberFor()`), the subscribe request, and the checkout component built the way
  Magento's layout builder does it, with Notification's real consent widget (the type arrives, the number field is bound
  in the template, nothing per keystroke, a changed number or country unticks and withdraws without recording the new
  one, no checkbox without a usable phone).
- `Test/Integration/SmsReminderFlowTest.php` (a Magento development database): guest consent and sign-up through
  Notification, the finder, and one text through a fake SMS provider – once per cart, beside the email's history.
  `SmsConsentRaceTest.php`: the checkbox unticked while the reminder goes out – a withdrawal stored before the claim
  stops the text (the claim checks the consent in the same statement); one after the provider accepted it changes
  nothing and allows no second text. Both use only their own cart and number and remove what they write.

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-abandoned-cart-sms/Test/Unit
MAGENTO_ROOT="$PWD" node --test vendor/commercekings/module-abandoned-cart-sms/Test/JavaScript/*.test.cjs
MAGENTO_ROOT="$PWD" vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-abandoned-cart-sms/Test/Integration
```
