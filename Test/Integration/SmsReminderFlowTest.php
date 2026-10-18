<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Test\Integration;

use CommerceKings\AbandonedCart\Api\CartDataInterface;
use CommerceKings\AbandonedCart\Api\RestoreLinkInterface;
use CommerceKings\AbandonedCart\Cron\FindAbandonedCarts;
use CommerceKings\AbandonedCart\Model\CartTable;
use CommerceKings\AbandonedCart\Model\Notification\CartReminder;
use CommerceKings\AbandonedCart\Model\Notification\CartSubscription;
use CommerceKings\AbandonedCart\Model\QuoteLifetime;
use CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart;
use CommerceKings\AbandonedCart\Model\ReminderTypePool;
use CommerceKings\AbandonedCartSms\Model\Config;
use CommerceKings\AbandonedCartSms\Model\SmsReminder;
use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\Notification\Api\QuietHoursInterface;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use CommerceKings\Notification\Model\AddressConsent;
use CommerceKings\Notification\Model\AddressConsentManagement;
use CommerceKings\Notification\Model\AddressConsentRepository;
use CommerceKings\Notification\Model\Config as NotificationConfig;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\Cooldown;
use CommerceKings\Notification\Model\PermissionResolver;
use CommerceKings\Notification\Model\ProviderPool;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use CommerceKings\Notification\Model\Sender;
use CommerceKings\Notification\Model\SubscriptionManagement;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterfaceFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The text reminder end to end on a real database, through Abandoned Cart's and Notification's own services: a guest
 * ticks the checkbox for the number (address consent), signs the cart up, the finder queues it as cart_reminder_sms
 * and the reminder sends one text through a fake SMS provider - once per cart, beside the email reminder's history.
 */
class SmsReminderFlowTest extends TestCase
{
    private const NUMBER = '+381601234567';

    private \Magento\Framework\ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private ?int $quoteId = null;
    /** @var array<int, array{0: Delivery, 1: DeliveryContent}> */
    private array $sent = [];
    private array $published = [];

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        try {
            $this->om->get(State::class)->setAreaCode('frontend');
        } catch (\Magento\Framework\Exception\LocalizedException) {
            // already set by an earlier test in this process
        }
        $this->resource = $this->om->get(ResourceConnection::class);
        $this->om->get(StoreManagerInterface::class)->setCurrentStore('default');
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        if ($this->quoteId !== null) {
            $contacts = $db->fetchCol($db->select()->from($this->resource->getTableName(SubscriptionManagement::TABLE),
                'contact_id')->where('type = ?', SmsReminder::CODE)->where('reference_id = ?', $this->quoteId));
            $db->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
                ['type = ?' => SmsReminder::CODE, 'reference_id = ?' => $this->quoteId]);
            $db->delete($this->resource->getTableName(DeliveryStateInterface::TABLE),
                ['type IN (?)' => [SmsReminder::CODE, CartReminder::CODE], 'reference_id = ?' => $this->quoteId]);
            $db->delete($this->resource->getTableName('quote'), ['entity_id = ?' => $this->quoteId]);
            if (array_filter($contacts)) {
                $db->delete($this->resource->getTableName(ContactRepository::TABLE), ['contact_id IN (?)' => array_filter($contacts)]);
            }
        }
        $db->delete($this->resource->getTableName(AddressConsentRepository::TABLE), ['type = ?' => SmsReminder::CODE]);
    }

    private function type(): SmsReminder
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getDelayMinutes')->willReturn(1);
        $config->method('getMaxPerHour')->willReturn(0);
        $config->method('getText')->willReturn('{{store}}: you left something in your cart. Complete your order: {{link}}'
            . ' Reply STOP to stop');
        $config->method('getStoreName')->willReturn('Acme');
        return new SmsReminder($config, $this->om->create(AddressConsent::class, ['typeCode' => SmsReminder::CODE]),
            new OncePerCart(), $this->om->get(SubscriptionRepositoryInterface::class), $this->om->get(CartDataInterface::class),
            $this->om->get(RestoreLinkInterface::class), $this->om->get(StoreManagerInterface::class),
            $this->om->get(\CommerceKings\Notification\Model\PhoneNormalizer::class));
    }

    private function quietHours(): QuietHoursInterface
    {
        return $this->createStub(QuietHoursInterface::class);
    }

    private function provider(): ProviderInterface
    {
        $provider = $this->createStub(ProviderInterface::class);
        $provider->method('getCode')->willReturn('fake_sms');
        $provider->method('getLabel')->willReturn('Fake SMS');
        $provider->method('getChannel')->willReturn(Channel::SMS);
        $provider->method('supportsOneClickUnsubscribe')->willReturn(false);
        $provider->method('send')->willReturnCallback(function (Delivery $delivery, DeliveryContent $content) {
            $this->sent[] = [$delivery, $content];
        });
        return $provider;
    }

    public function testAGuestsNumberGetsOneTextForTheCart(): void
    {
        $type = $this->type();
        $subscriptionType = $this->om->create(CartSubscription::class, ['type' => $type]);
        $types = new SubscriptionTypePool([SmsReminder::CODE => $subscriptionType]);

        // a guest cart with an item, its masked id as checkout has it
        $store = $this->om->get(StoreManagerInterface::class)->getStore('default');
        $quote = $this->om->get(QuoteFactory::class)->create();
        $quote->setStore($store)->setIsActive(true)->setCustomerIsGuest(true);
        $this->om->get(CartRepositoryInterface::class)->save($quote);
        $this->quoteId = (int)$quote->getId();
        $this->resource->getConnection()->update($this->resource->getTableName('quote'), ['items_count' => 1],
            ['entity_id = ?' => $this->quoteId]);
        $mask = $this->om->create(QuoteIdMaskFactory::class)->create()->setQuoteId($this->quoteId)->save();
        $masked = (string)$mask->getMaskedId();

        // the checkbox (address consent for the number), then the sign-up
        $wording = $this->om->get(NotificationConfig::class)->getAddressConsentWording((int)$store->getId(), Channel::SMS);
        $this->assertNotSame('', $wording, 'the default checkbox text for text messages');
        $consents = $this->om->create(AddressConsentManagement::class, ['types' => $types]);
        $this->assertTrue($consents->recordGuest(SmsReminder::CODE, $masked, '+381 60 123 4567', hash('sha256', $wording)));
        $management = $this->om->create(SubscriptionManagement::class, ['types' => $types,
            'permissions' => $this->om->create(PermissionResolver::class, ['types' => $types])]);
        $this->assertTrue($management->subscribeGuest(SmsReminder::CODE, $masked, '+381 60 123 4567'));
        // Notification keeps the contact as entered; the type returns the number in E.164 (the cooldown's key)
        $this->assertSame(self::NUMBER, $type->getRecipient($this->quoteId));

        // idle past the delay
        $db = $this->resource->getConnection();
        $past = gmdate('Y-m-d H:i:s', time() - 300);
        $db->update($this->resource->getTableName('quote'), ['updated_at' => $past], ['entity_id = ?' => $this->quoteId]);
        $db->update($this->resource->getTableName(SubscriptionManagement::TABLE), ['captured_at' => $past],
            ['type = ?' => SmsReminder::CODE, 'reference_id = ?' => $this->quoteId]);

        // the finder queues it as the text reminder
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('publish')->willReturnCallback(function ($code, $reference) {
            $this->published[] = [$code, $reference];
        });
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([$store]);
        $notificationConfig = $this->createStub(NotificationConfig::class);
        $notificationConfig->method('getCooldownDays')->willReturn(0);
        $carts = $this->om->get(CartTable::class);
        $finder = new FindAbandonedCarts($this->resource, $this->om->get(SubscriptionPermissionInterface::class), $queue,
            $this->om->get(QuoteLifetime::class), $stores, new NullLogger(),
            $this->om->create(Cooldown::class, ['config' => $notificationConfig]), $carts, $this->quietHours(),
            new ReminderTypePool([SmsReminder::CODE => $type]));
        $this->assertSame(1, $finder->execute());
        $this->assertSame(SmsReminder::CODE, $this->published[0][0]);

        // the reminder sends one text through the sms provider
        $sender = new Sender(new ProviderPool($notificationConfig, [$this->provider()]), $this->createStub(Emulation::class),
            $this->om->create(Cooldown::class, ['config' => $notificationConfig]), $notificationConfig, new NullLogger(),
            $this->om->get(RecipientNormalizerPool::class), $this->om->get(StopListInterface::class), $this->quietHours());
        $reminder = new CartReminder($this->resource, $this->om->get(CartRepositoryInterfaceFactory::class), $sender,
            $this->om->get(SubscriptionPermissionInterface::class), new NullLogger(), $this->om->get(QuoteLifetime::class),
            $carts, $this->om->get(LockManagerInterface::class), $this->om->get(\CommerceKings\Notification\Api\SubscriptionRemovalInterface::class),
            $this->quietHours(), $type);
        $reminder->process((string)$this->published[0][1]);
        $this->assertCount(1, $this->sent);
        [$delivery, $content] = $this->sent[0];
        $this->assertSame([Channel::SMS, self::NUMBER, SmsReminder::CODE, true],
            [$delivery->channel, $delivery->recipient, $delivery->type, $delivery->stoppable]);
        $this->assertStringStartsWith('Acme: you left something in your cart. Complete your order: http', (string)$content->text);
        $this->assertStringContainsString('checkout/cart', (string)$content->text, 'the cart page');
        $this->assertStringEndsWith('Reply STOP to stop', (string)$content->text);

        // once per cart: the text reminder's history, not the email's
        $this->assertNotNull($carts->forType(SmsReminder::CODE)->load($this->quoteId)['sent_at'] ?? null);
        $this->assertNull($carts->load($this->quoteId), 'the email reminder never queued this cart');
        $reminder->process((string)$this->published[0][1]);
        $this->assertCount(1, $this->sent, 'a second message for the cart sends nothing');
        $this->assertSame(0, $finder->execute(), 'not due again');
    }
}
