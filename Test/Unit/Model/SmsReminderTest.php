<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Test\Unit\Model;

use CommerceKings\AbandonedCart\Api\CartDataInterface;
use CommerceKings\AbandonedCart\Api\Data\CartFacts;
use CommerceKings\AbandonedCart\Api\Data\ReminderMessage;
use CommerceKings\AbandonedCart\Api\RestoreLinkInterface;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\AbandonedCartSms\Model\Config;
use CommerceKings\AbandonedCartSms\Model\SmsReminder;
use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class SmsReminderTest extends TestCase
{
    private string $text = '{{store}}: {{name}}, your {{product}} ({{total}}) is waiting: {{link}} Reply STOP to stop';
    private string $storeName = 'Acme';
    private ?array $recipient = ['channel' => 'sms', 'recipient' => '+381601234567'];
    private bool $subscribed = true;
    private string $firstName = 'Jane';
    private array $items = [['name' => 'Notebook A5', 'qty' => 1.0, 'price' => '€12.00', 'image' => '', 'options' => []]];
    private array $links = [];
    private ?SubscriptionPermissionInterface $permission = null;

    private function reminder(): SmsReminder
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn ($storeId) => $storeId === 1);
        $config->method('getDelayMinutes')->willReturn(45);
        $config->method('getMaxPerHour')->willReturn(300);
        $config->method('getText')->willReturnCallback(fn () => $this->text);
        $config->method('getStoreName')->willReturnCallback(fn () => $this->storeName);
        $this->permission ??= $this->createStub(SubscriptionPermissionInterface::class);
        $subscriptions = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptions->method('find')->willReturnCallback(fn ($type, $quoteId) => $this->subscribed && $type === 'cart_reminder_sms'
            ? [new Subscription(9, $type, $quoteId, 1, 5, null, 'consent-1', '2026-10-18 12:00:00')] : []);
        $subscriptions->method('getRecipient')->willReturnCallback(fn () => $this->recipient);
        $cartData = $this->createStub(CartDataInterface::class);
        $cartData->method('get')->willReturnCallback(fn () => new CartFacts($this->firstName, $this->items, '€12.00',
            'https://shop.test/'));
        $links = $this->createStub(RestoreLinkInterface::class);
        $links->method('getUrl')->willReturnCallback(function ($quote, $type) {
            $this->links[] = $type;
            return 'https://shop.test/checkout/cart/';
        });
        $store = $this->createStub(Store::class);
        $store->method('getFrontendName')->willReturn('Acme Store View');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return new SmsReminder($config, $this->permission, new OncePerCart(), $subscriptions, $cartData, $links, $stores);
    }

    private function message(): ReminderMessage
    {
        return new ReminderMessage(42, 1, 9, '+381601234567', 'consent-1', fn () => $this->createStub(Quote::class),
            fn () => true);
    }

    private function text(): string
    {
        return (string)(($this->reminder()->createDelivery($this->message())->content)()->text);
    }

    public function testTheTextReminderOnTheSmsChannelWithItsOwnConsentAndSettings(): void
    {
        $this->permission = $this->createStub(SubscriptionPermissionInterface::class);
        $reminder = $this->reminder();
        $this->assertSame(['cart_reminder_sms', Channel::SMS, 'cart_reminder_sms'],
            [$reminder->getCode(), $reminder->getChannel(), $reminder->getPurpose()]);
        $this->assertSame($this->permission, $reminder->getPermission(), 'the checkbox for the number');
        $this->assertNull($reminder->getSubscriber());
        $this->assertSame([true, false, 45, 300], [$reminder->isEnabled(1), $reminder->isEnabled(2),
            $reminder->getDelayMinutes(1), $reminder->getMaxPerHour()]);
        $this->assertInstanceOf(OncePerCart::class, $reminder->getPolicy());
    }

    public function testTheRecipientIsTheSubscribedNumber(): void
    {
        $this->assertSame('+381601234567', $this->reminder()->getRecipient(42));
        $this->recipient = ['channel' => 'email', 'recipient' => 'a@test.com'];
        $this->assertNull($this->reminder()->getRecipient(42), 'never another channel\'s address');
        $this->recipient = null;
        $this->assertNull($this->reminder()->getRecipient(42), 'contact gone');
        $this->subscribed = false;
        $this->assertNull($this->reminder()->getRecipient(42), 'not signed up for texts');
    }

    public function testTheDeliveryIsAStoppableTextOfThisType(): void
    {
        $message = $this->message();
        $delivery = $this->reminder()->createDelivery($message);
        $this->assertSame([1, Channel::SMS, '+381601234567', '', '', true, true, 'cart_reminder_sms'],
            [$delivery->storeId, $delivery->channel, $delivery->recipient, $delivery->templateId, $delivery->sender,
                $delivery->useCooldown, $delivery->stoppable, $delivery->type]);
        $this->assertSame($message->isRecipientCurrent, $delivery->isRecipientCurrent, 'the sender rechecks the number');
    }

    public function testTheTextFillsItsPlaceholders(): void
    {
        $this->assertSame('Acme: Jane, your Notebook A5 (€12.00) is waiting: https://shop.test/checkout/cart/'
            . ' Reply STOP to stop', $this->text());
        $this->assertSame(['cart_reminder_sms'], $this->links, 'the restore link for this type: the cart page');
    }

    public function testAnEmptyNameLeavesNoStraySpaceOrComma(): void
    {
        $this->firstName = '';
        $this->text = 'Hi {{name}}, your cart at {{store}} is waiting: {{link}}';
        $this->assertSame('Hi, your cart at Acme is waiting: https://shop.test/checkout/cart/', $this->text());
    }

    public function testALongProductNameIsCutAndTheStoreViewNameIsTheFallback(): void
    {
        $this->storeName = '';
        $this->items[0]['name'] = "A very long product name that would\nnever fit in a single text message";
        $this->text = '{{store}}: {{product}}';
        $text = $this->text();
        $this->assertSame('Acme Store View: A very long product name that would n...', $text);
        $this->assertSame(40, mb_strlen(substr($text, strlen('Acme Store View: '))), 'at most 40 characters, GSM-7 dots');
    }

    public function testAnEmptyCartListLeavesTheProductOut(): void
    {
        $this->items = [];
        $this->text = '{{store}}: {{product}} {{link}}';
        $this->assertSame('Acme: https://shop.test/checkout/cart/', $this->text());
    }
}
