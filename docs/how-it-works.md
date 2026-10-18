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
- **Checkout** (`checkout_index_index.xml`, `templates/checkout/sms.phtml`, `ViewModel\Checkout`): rendered only when
  the type is on in the store, an `sms` provider is installed and the store view has a checkbox text. The phone field
  (`js/sms-phone`) writes the number in E.164 (`js/sms-phone-core`) to a hidden field, which is the phone of
  Notification's `address-consent.phtml` checkbox. When the checkbox's consent is recorded
  (`ckAddressConsent:recorded`), the cart is signed up: guests with the masked cart id, customers with the quote id
  and `/mine`.

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
  view model, and the wiring – every Abandoned Cart and Notification class named in PHP or di.xml is `@api`, and the
  cart data and restore link come through proxies.
- `Test/JavaScript`: E.164 conversion (international, national with trunk prefix, North America, Italy, refusals) and
  the subscribe request.
- `Test/Integration/SmsReminderFlowTest.php` (a Magento development database): guest consent and sign-up through
  Notification, the finder, and one text through a fake SMS provider – once per cart, beside the email's history.

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-abandoned-cart-sms/Test/Unit
MAGENTO_ROOT="$PWD" node --test vendor/commercekings/module-abandoned-cart-sms/Test/JavaScript/*.test.cjs
MAGENTO_ROOT="$PWD" vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-abandoned-cart-sms/Test/Integration
```
