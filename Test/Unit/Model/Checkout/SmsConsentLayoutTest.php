<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Test\Unit\Model\Checkout;

use CommerceKings\AbandonedCartSms\Model\Checkout\SmsConsentLayout;
use CommerceKings\AbandonedCartSms\ViewModel\Checkout;
use CommerceKings\Notification\ViewModel\AddressConsent;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use PHPUnit\Framework\TestCase;

/** The checkbox goes right under the shipping phone: in the address form, or after a customer's saved addresses. */
class SmsConsentLayoutTest extends TestCase
{
    private bool $available = true;
    private bool $loggedIn = false;
    private array $addresses = [];

    private function process(array $jsLayout): array
    {
        $checkout = $this->createStub(Checkout::class);
        $checkout->method('isAvailable')->willReturnCallback(fn () => $this->available);
        $checkout->method('getOptions')->willReturn(['type' => 'cart_reminder_sms', 'country' => 'RS',
            'urls' => ['guest' => '/rest/default/V1/ck-notification/subscriptions', 'mine' => '/rest/default/V1/ck-notification/subscriptions/mine']]);
        $consent = $this->createStub(AddressConsent::class);
        $consent->method('getWording')->willReturnCallback(fn ($channel) => $channel === 'sms' ? 'Yes, text me. Reply STOP to stop.' : '');
        $consent->method('getWordingHash')->willReturn('hash-1');
        $consent->method('getUrls')->willReturn(['guest' => '/rest/default/V1/ck-notification/address-consents',
            'mine' => '/rest/default/V1/ck-notification/address-consents/mine']);
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getAddresses')->willReturnCallback(fn () => $this->addresses);
        $session = $this->createStub(Session::class);
        $session->method('isLoggedIn')->willReturnCallback(fn () => $this->loggedIn);
        $session->method('getCustomerDataObject')->willReturn($customer);
        return (new SmsConsentLayout($checkout, $consent, $session))->process($jsLayout);
    }

    private static function checkout(?int $telephoneSortOrder = 120): array
    {
        $fields = ['firstname' => ['sortOrder' => 10], 'postcode' => ['sortOrder' => 110]];
        if ($telephoneSortOrder !== null) {
            $fields['telephone'] = ['sortOrder' => $telephoneSortOrder];
        }
        return ['components' => ['checkout' => ['children' => ['steps' => ['children' => ['shipping-step' => [
            'children' => ['shippingAddress' => ['children' => [
                'address-list' => ['component' => 'Magento_Checkout/js/view/shipping-address/list'],
                'shipping-address-fieldset' => ['children' => $fields],
            ]]]]]]]]]];
    }

    private static function shipping(array $jsLayout): array
    {
        return $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
            ['shippingAddress'];
    }

    public function testRightUnderTheTelephoneInTheAddressForm(): void
    {
        $fields = self::shipping($this->process(self::checkout(130)))['children']['shipping-address-fieldset']['children'];
        $component = $fields['ck-sms-consent'];
        $this->assertSame('CommerceKings_AbandonedCartSms/js/view/sms-consent', $component['component']);
        $this->assertSame(131, $component['sortOrder'], 'right after the telephone');
        $this->assertSame([
            'type' => 'cart_reminder_sms',
            'country' => 'RS',
            'urls' => ['guest' => '/rest/default/V1/ck-notification/subscriptions', 'mine' => '/rest/default/V1/ck-notification/subscriptions/mine'],
            'consent' => ['wording' => 'Yes, text me. Reply STOP to stop.', 'wordingHash' => 'hash-1',
                'urls' => ['guest' => '/rest/default/V1/ck-notification/address-consents',
                    'mine' => '/rest/default/V1/ck-notification/address-consents/mine']],
            'placement' => 'address-form',
        ], $component['config']);
        $this->assertSame(121, self::shipping($this->process(self::checkout(null)))['children']
            ['shipping-address-fieldset']['children']['ck-sms-consent']['sortOrder'], 'Magento\'s default telephone order');
    }

    /** A customer with saved addresses picks one from the list; the form is only the "New Address" pop-up. */
    public function testAfterTheSavedAddressesForACustomerWhoHasThem(): void
    {
        $this->loggedIn = true;
        $this->addresses = [$this->createStub(AddressInterface::class)];
        $shipping = self::shipping($this->process(self::checkout()));
        $this->assertArrayNotHasKey('ck-sms-consent', $shipping['children']['shipping-address-fieldset']['children'],
            'one checkbox only');
        $this->assertSame('address-list-additional-addresses', $shipping['children']['ck-sms-consent']['displayArea']);
        $this->assertSame('address-list', $shipping['children']['ck-sms-consent']['config']['placement']);

        $this->addresses = [];
        $this->assertArrayHasKey('ck-sms-consent', self::shipping($this->process(self::checkout()))['children']
            ['shipping-address-fieldset']['children'], 'a customer without addresses fills in the form');
    }

    public function testNothingWhenTextRemindersAreNotAvailableOrThereIsNoShippingStep(): void
    {
        $this->available = false;
        $this->assertSame(self::checkout(), $this->process(self::checkout()));
        $this->available = true;
        $virtual = ['components' => ['checkout' => ['children' => ['steps' => ['children' => ['billing-step' => []]]]]]];
        $this->assertSame($virtual, $this->process($virtual), 'a virtual cart has no shipping phone');
    }
}
