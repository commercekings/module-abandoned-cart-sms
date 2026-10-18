# Compatibility and verification

The first-release runtime target is Magento Open Source 2.4.9 with PHP 8.5 and the Luma checkout.
The Composer PHP range is a dependency constraint, not a claim that every Magento/PHP
combination has passed runtime testing.

**Minimum: Magento Open Source 2.4.4**, enforced by `magento/framework: ^103.0.4`, like
`commercekings/module-abandoned-cart`. It needs Abandoned Cart with reminder types (its `Api\ReminderTypeInterface`)
and Notification with address consent and `CooldownInterface::isRecentFor()`.

| Magento Open Source | PHP | Runtime verification |
| --- | --- | --- |
| 2.4.9 | 8.5 | Pending: Composer, PHP lint, DI compilation, unit, JavaScript and integration tests on the shared sandbox |
| 2.4.4–2.4.8 | 8.1–8.4 | Unverified; use a matching Magento dependency set and services |

Locally verified so far: PHP lint, the unit tests (PHP 8.4) and the JavaScript tests (Node 22). The integration test
sends through a fake SMS provider; these checks do not verify delivery through a real provider or carrier.

The GitHub workflow checks package metadata, PHP syntax on 8.1–8.5 and Git archive contents, and runs the unit and
JavaScript tests on Magento 2.4.9 with PHP 8.5. It does not run integration tests. Latest results are available in
[GitHub Actions](https://github.com/commercekings/module-abandoned-cart-sms/actions).

For runtime tests, install the source checkout in a compatible Magento development project
and run the suites in `Test/`. Distribution archives omit `Test/`; source checkouts retain it.
Keep package constraints and the verified matrix aligned before each release.

Supported Magento service/version combinations are listed in
[Adobe's system requirements](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements).
