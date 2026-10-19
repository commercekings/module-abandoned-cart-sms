<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Test\Integration;

use CommerceKings\AbandonedCart\Api\CartDataInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderQuery;
use CommerceKings\AbandonedCart\Api\Data\ReminderState;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
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
use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\Notification\Api\QuietHoursInterface;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
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
 * The shopper unticks the text reminder checkbox (the address consent is withdrawn) while the reminder is being sent,
 * on a real database with a fake SMS provider: a withdrawal committed before the consumer's claim stops the send - the
 * claim checks the consent in the same statement - and one after the claim cannot undo a text the provider accepted,
 * nor make a second one go out. Only this test's cart and number are touched; everything it writes is removed.
 */
class SmsConsentRaceTest extends TestCase
{
    private \Magento\Framework\ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private ?int $quoteId = null;
    private string $masked = '';
    /** this run's number, E.164 (+38160 and seven random digits) */
    private string $number;
    private int $sent = 0;
    private array $published = [];
    private SmsReminder $type;
    private SubscriptionTypePool $types;
    /** runs once inside the fake provider's send(), i.e. after the claim */
    private ?\Closure $duringSend = null;

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
        $this->number = '+38160' . sprintf('%07d', random_int(0, 9999999));
        $this->type = $this->type();
        $this->types = new SubscriptionTypePool([SmsReminder::CODE => $this->om->create(CartSubscription::class,
            ['type' => $this->type])]);
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        $contacts = [];
        if ($this->quoteId !== null) {
            $contacts = $db->fetchCol($db->select()->from($this->resource->getTableName(SubscriptionManagement::TABLE),
                'contact_id')->where('type = ?', SmsReminder::CODE)->where('reference_id = ?', $this->quoteId));
            $db->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
                ['type = ?' => SmsReminder::CODE, 'reference_id = ?' => $this->quoteId]);
            $db->delete($this->resource->getTableName(DeliveryStateInterface::TABLE),
                ['type IN (?)' => [SmsReminder::CODE, CartReminder::CODE], 'reference_id = ?' => $this->quoteId]);
            // the masked id goes with the quote (foreign key)
            $db->delete($this->resource->getTableName('quote'), ['entity_id = ?' => $this->quoteId]);
        }
        $website = (int)$this->om->get(StoreManagerInterface::class)->getStore('default')->getWebsiteId();
        $contacts[] = $this->om->get(ContactRepository::class)->find($website, $this->number, Channel::SMS);
        if (array_filter($contacts)) {
            $db->delete($this->resource->getTableName(ContactRepository::TABLE), ['contact_id IN (?)' => array_filter($contacts)]);
        }
        $keys = (fn (string $number) => $this->keys(Channel::SMS, $number))
            ->call($this->om->get(AddressConsentRepository::class), $this->number);
        $db->delete($this->resource->getTableName(AddressConsentRepository::TABLE),
            ['type = ?' => SmsReminder::CODE, 'recipient_key IN (?)' => $keys]);
        $db->delete($this->resource->getTableName('ck_notification_cooldown'),
            ['recipient_hash = ?' => $this->om->get(Cooldown::class)->hash(Cooldown::key(Channel::SMS, $this->number))]);
    }

    /** The text reminder; $permission replaces its address consent (CartReminder asks the type's own permission). */
    private function type(?SubscriptionPermissionInterface $permission = null): SmsReminder
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getDelayMinutes')->willReturn(1);
        $config->method('getMaxPerHour')->willReturn(0);
        $config->method('getText')->willReturn('{{store}}: your cart is waiting: {{link}} Reply STOP to stop');
        $config->method('getStoreName')->willReturn('Acme');
        // OncePerCart; the finder's selection is limited to this test's cart, so other carts are never queued
        $policy = new class(new OncePerCart(), fn () => (int)$this->quoteId) implements ReminderPolicyInterface {
            public function __construct(private readonly OncePerCart $policy, private readonly \Closure $quoteId) {}
            public function getDescription(): string { return $this->policy->getDescription(); }
            public function canSend(ReminderState $state): bool { return $this->policy->canSend($state); }
            public function dueCondition(ReminderQuery $query): string
            {
                return sprintf('(%s) AND %s = %d', $this->policy->dueCondition($query), $query->quoteId(), ($this->quoteId)());
            }
        };
        return new SmsReminder($config, $permission ?? $this->addressConsent(), $policy, $this->om->get(SubscriptionRepositoryInterface::class), $this->om->get(CartDataInterface::class),
            $this->om->get(RestoreLinkInterface::class), $this->om->get(StoreManagerInterface::class),
            $this->om->get(\CommerceKings\Notification\Model\PhoneNormalizer::class));
    }

    private function addressConsent(): SubscriptionPermissionInterface
    {
        return $this->om->create(AddressConsent::class, ['typeCode' => SmsReminder::CODE]);
    }

    /** A guest cart with an item, the checkbox ticked for the number and the cart signed up, idle past the delay. */
    private function signedUpCart(): void
    {
        $store = $this->om->get(StoreManagerInterface::class)->getStore('default');
        $quote = $this->om->get(QuoteFactory::class)->create();
        $quote->setStore($store)->setIsActive(true)->setCustomerIsGuest(true);
        $this->om->get(CartRepositoryInterface::class)->save($quote);
        $this->quoteId = (int)$quote->getId();
        $db = $this->resource->getConnection();
        $db->update($this->resource->getTableName('quote'), ['items_count' => 1], ['entity_id = ?' => $this->quoteId]);
        $this->masked = (string)$this->om->create(QuoteIdMaskFactory::class)->create()->setQuoteId($this->quoteId)->save()
            ->getMaskedId();
        $wording = $this->om->get(NotificationConfig::class)->getAddressConsentWording((int)$store->getId(), Channel::SMS);
        $this->assertTrue($this->consents()->recordGuest(SmsReminder::CODE, $this->masked, $this->number,
            hash('sha256', $wording)));
        // no subscription observers: a provider module's opt-in confirmation would queue a real text to the number
        $management = $this->om->create(SubscriptionManagement::class, ['types' => $this->types,
            'permissions' => $this->om->create(PermissionResolver::class, ['types' => $this->types]),
            'events' => $this->createStub(\Magento\Framework\Event\ManagerInterface::class)]);
        $this->assertTrue($management->subscribeGuest(SmsReminder::CODE, $this->masked, $this->number));
        $past = gmdate('Y-m-d H:i:s', time() - 300);
        $db->update($this->resource->getTableName('quote'), ['updated_at' => $past], ['entity_id = ?' => $this->quoteId]);
        $db->update($this->resource->getTableName(SubscriptionManagement::TABLE), ['captured_at' => $past],
            ['type = ?' => SmsReminder::CODE, 'reference_id = ?' => $this->quoteId]);
    }

    private function consents(): AddressConsentManagement
    {
        return $this->om->create(AddressConsentManagement::class, ['types' => $this->types]);
    }

    /** The shopper unticks the box: the consent for the number is withdrawn (and the sign-up with it). */
    private function untick(): void
    {
        $this->assertTrue($this->consents()->recordGuest(SmsReminder::CODE, $this->masked, $this->number, '', false));
    }

    private function finder(): FindAbandonedCarts
    {
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('publish')->willReturnCallback(function ($code, $reference) {
            $this->published[] = $reference;
        });
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([$this->om->get(StoreManagerInterface::class)->getStore('default')]);
        return new FindAbandonedCarts($this->resource, $this->om->get(SubscriptionPermissionInterface::class), $queue,
            $this->om->get(QuoteLifetime::class), $stores, new NullLogger(), $this->cooldown(),
            $this->om->get(CartTable::class), $this->createStub(QuietHoursInterface::class),
            new ReminderTypePool([SmsReminder::CODE => $this->type]));
    }

    private function cooldown(): Cooldown
    {
        $config = $this->createStub(NotificationConfig::class);
        $config->method('getCooldownDays')->willReturn(0);
        return $this->om->create(Cooldown::class, ['config' => $config]);
    }

    /** The consumer; $permission replaces the type's address consent (e.g. to withdraw right after the check). */
    private function reminder(?SubscriptionPermissionInterface $permission = null): CartReminder
    {
        $config = $this->createStub(NotificationConfig::class);
        $config->method('getCooldownDays')->willReturn(0);
        $provider = $this->createStub(ProviderInterface::class);
        $provider->method('getCode')->willReturn('fake_sms');
        $provider->method('getLabel')->willReturn('Fake SMS');
        $provider->method('getChannel')->willReturn(Channel::SMS);
        $provider->method('supportsOneClickUnsubscribe')->willReturn(false);
        $provider->method('send')->willReturnCallback(function (Delivery $delivery, DeliveryContent $content) {
            $this->sent++; // the provider has it: accepted
            if ($this->duringSend !== null) {
                [$during, $this->duringSend] = [$this->duringSend, null];
                $during();
            }
        });
        $sender = new Sender(new ProviderPool($config, [$provider]), $this->createStub(Emulation::class),
            $this->cooldown(), $config, new NullLogger(), $this->om->get(RecipientNormalizerPool::class),
            $this->om->get(StopListInterface::class), $this->createStub(QuietHoursInterface::class));
        return new CartReminder($this->resource, $this->om->get(CartRepositoryInterfaceFactory::class), $sender,
            $permission ?? $this->om->get(SubscriptionPermissionInterface::class), new NullLogger(),
            $this->om->get(QuoteLifetime::class), $this->om->get(CartTable::class), $this->om->get(LockManagerInterface::class),
            $this->om->get(\CommerceKings\Notification\Api\SubscriptionRemovalInterface::class),
            $this->createStub(QuietHoursInterface::class), $permission === null ? $this->type : $this->type($permission));
    }

    /** The cart's send history of the text reminder. */
    private function history(): ?array
    {
        return $this->om->get(CartTable::class)->forType(SmsReminder::CODE)->load((int)$this->quoteId);
    }

    private function evidence(): array
    {
        $keys = (fn (string $number) => $this->keys(Channel::SMS, $number))
            ->call($this->om->get(AddressConsentRepository::class), $this->number);
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->resource->getTableName(AddressConsentRepository::TABLE))
            ->where('type = ?', SmsReminder::CODE)->where('recipient_key IN (?)', $keys));
    }

    public function testAWithdrawalCommittedBeforeTheClaimStopsTheSend(): void
    {
        $this->signedUpCart();
        $this->assertSame(1, $this->finder()->execute());
        // the consumer's check passes; the shopper unticks before its claim: the claim's own consent condition decides
        $actual = $this->addressConsent();
        $permission = new class($actual, fn () => $this->untick()) implements SubscriptionPermissionInterface {
            public function __construct(private readonly SubscriptionPermissionInterface $actual,
                private readonly \Closure $untick) {}
            public function check(SubscriptionTypeInterface $type, Target $target, ?string $recipient = null): Permission
            {
                return $this->actual->check($type, $target, $recipient);
            }
            public function validCondition(string $referenceColumn): string
            {
                return $this->actual->validCondition($referenceColumn);
            }
            public function isValidFor(string $reference, string $purpose, int $storeId): bool
            {
                $valid = $this->actual->isValidFor($reference, $purpose, $storeId);
                ($this->untick)(); // committed: its own statements, no transaction around the consumer
                return $valid;
            }
            public function isOptOut(): bool
            {
                return $this->actual->isOptOut();
            }
        };
        $this->reminder($permission)->process((string)$this->published[0]);
        $this->assertNotNull($this->evidence()[0]['withdrawn_at'], 'the consumer checked the consent, then it was withdrawn');
        $this->assertSame(0, $this->sent, 'no text after the withdrawal');
        $this->assertNull($this->history()['sent_at'] ?? null, 'not claimed');
        $this->reminder()->process((string)$this->published[0]);
        $this->assertSame(0, $this->sent, 'nor on a redelivery');
        $this->assertSame(0, $this->finder()->execute(), 'nothing left to queue: the sign-up ended with the consent');
    }

    public function testAWithdrawalAfterTheClaimCannotUndoAnAcceptedSendNorAddASecondOne(): void
    {
        $this->signedUpCart();
        $this->assertSame(1, $this->finder()->execute());
        $this->duringSend = fn () => $this->untick(); // the provider has accepted it when the shopper unticks
        $this->reminder()->process((string)$this->published[0]);
        $this->assertSame(1, $this->sent);
        $this->assertNotNull($this->history()['sent_at'] ?? null, 'the claim stays: the text went out');
        $this->assertNotNull($this->evidence()[0]['withdrawn_at'], 'the withdrawal is stored as it came');
        $this->reminder()->process((string)$this->published[0]);
        $this->assertSame(1, $this->sent, 'a redelivered message sends nothing');
        $this->assertSame(0, $this->finder()->execute(), 'and the cart is not queued again');
    }

    public function testControlWithoutAWithdrawalTheTextGoesOutOnce(): void
    {
        $this->signedUpCart();
        $this->assertSame(1, $this->finder()->execute());
        $this->reminder()->process((string)$this->published[0]);
        $this->reminder()->process((string)$this->published[0]);
        $this->assertSame(1, $this->sent);
    }
}
