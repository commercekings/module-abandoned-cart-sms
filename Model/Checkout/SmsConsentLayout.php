<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Model\Checkout;

use CommerceKings\AbandonedCartSms\ViewModel\Checkout;
use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\ViewModel\AddressConsent;
use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session;

/**
 * Luma checkout: puts the text reminder checkbox (js/view/sms-consent) right under the shipping phone - in the address
 * form (guests, and customers without a saved address), or after the saved addresses for a customer who has them (the
 * form is then only the "New Address" pop-up, and the selected address's phone is the number). Only where text
 * reminders are available (ViewModel\Checkout): an SMS provider, the type on in the store and a checkbox text - and
 * never for a virtual cart (no shipping address, no shipping phone). The notification type goes as "subscriptionType":
 * Magento's layout builder takes a component's "type" for itself.
 */
class SmsConsentLayout implements LayoutProcessorInterface
{
    private const SHIPPING = ['components', 'checkout', 'children', 'steps', 'children', 'shipping-step', 'children',
        'shippingAddress'];
    /** Magento's default for the telephone field, when the form does not say. */
    private const TELEPHONE_SORT_ORDER = 120;

    public function __construct(
        private readonly Checkout $checkout,
        private readonly AddressConsent $addressConsent,
        private readonly Session $customerSession,
        private readonly CheckoutSession $checkoutSession
    ) {
    }

    public function process($jsLayout)
    {
        if (!$this->checkout->isAvailable() || $this->checkoutSession->getQuote()->isVirtual()) {
            return $jsLayout;
        }
        $shipping = &$jsLayout;
        foreach (self::SHIPPING as $key) {
            if (!isset($shipping[$key]) || !is_array($shipping[$key])) {
                return $jsLayout; // a checkout without Luma's shipping address step
            }
            $shipping = &$shipping[$key];
        }
        $options = $this->checkout->getOptions();
        $component = [
            'component' => 'CommerceKings_AbandonedCartSms/js/view/sms-consent',
            'config' => [
                'subscriptionType' => $options['type'],
                'country' => $options['country'],
                'urls' => $options['urls'],
                'consent' => [
                    'wording' => $this->addressConsent->getWording(Channel::SMS),
                    'wordingHash' => $this->addressConsent->getWordingHash(Channel::SMS),
                    'urls' => $this->addressConsent->getUrls(),
                ],
            ],
        ];
        if ($this->hasSavedAddresses()) {
            $component['config']['placement'] = 'address-list';
            $component['displayArea'] = 'address-list-additional-addresses';
            $shipping['children']['ck-sms-consent'] = $component;
            return $jsLayout;
        }
        $fields = &$shipping['children']['shipping-address-fieldset']['children'];
        $component['config']['placement'] = 'address-form';
        $component['sortOrder'] = (int)($fields['telephone']['sortOrder'] ?? self::TELEPHONE_SORT_ORDER) + 1;
        $fields['ck-sms-consent'] = $component;
        return $jsLayout;
    }

    private function hasSavedAddresses(): bool
    {
        return $this->customerSession->isLoggedIn()
            && $this->customerSession->getCustomerDataObject()->getAddresses() !== [];
    }
}
