<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Test\Unit\ViewModel;

use CommerceKings\AbandonedCartSms\Model\Config;
use CommerceKings\AbandonedCartSms\ViewModel\Checkout;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\ViewModel\AddressConsent;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CheckoutTest extends TestCase
{
    private bool $enabled = true;
    private string $wording = 'Yes, text me at this number. Reply STOP to stop.';
    /** @var string[] channels of the installed providers */
    private array $channels = ['email', 'sms'];

    private function viewModel(): Checkout
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($storeId) => $this->enabled && $storeId === 3);
        $config->method('getDefaultCountry')->willReturn('RS');
        $providers = $this->createStub(ProviderPoolInterface::class);
        $providers->method('getAll')->willReturnCallback(fn () => array_map(function ($channel) {
            $provider = $this->createStub(ProviderInterface::class);
            $provider->method('getChannel')->willReturn($channel);
            return $provider;
        }, $this->channels));
        $consent = $this->createStub(AddressConsent::class);
        $consent->method('getWording')->willReturnCallback(fn ($channel) => $channel === 'sms' ? $this->wording : '');
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getCode')->willReturn('us_en');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getBaseUrl')->willReturn('https://shop.test/');
        return new Checkout($config, $providers, $consent, $stores, $url);
    }

    public function testShownWithTextRemindersOnAnSmsProviderAndACheckboxText(): void
    {
        $this->assertTrue($this->viewModel()->isAvailable());
        $this->enabled = false;
        $this->assertFalse($this->viewModel()->isAvailable(), 'text reminders off in this store');
        $this->enabled = true;
        $this->channels = ['email', 'whatsapp'];
        $this->assertFalse($this->viewModel()->isAvailable(), 'no SMS provider installed');
        $this->channels = ['sms'];
        $this->wording = '';
        $this->assertFalse($this->viewModel()->isAvailable(), 'no checkbox text: no consent can be given');
    }

    public function testOptionsForTheCheckoutScript(): void
    {
        $this->assertSame([
            'type' => 'cart_reminder_sms',
            'country' => 'RS',
            'urls' => ['guest' => 'https://shop.test/rest/us_en/V1/ck-notification/subscriptions',
                'mine' => 'https://shop.test/rest/us_en/V1/ck-notification/subscriptions/mine'],
        ], $this->viewModel()->getOptions());
        $this->assertSame('cart_reminder_sms', $this->viewModel()->getType());
    }
}
