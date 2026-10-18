<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Model;

use CommerceKings\AbandonedCart\Api\CartDataInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderMessage;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\ReminderTypeInterface;
use CommerceKings\AbandonedCart\Api\RestoreLinkInterface;
use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The cart reminder as a text ("cart_reminder_sms"), next to Abandoned Cart's email: the number the shopper gave at
 * checkout with its own consent (Notification's address consent, a checkbox per number), this module's settings and
 * text. Sent by whichever SMS provider is selected for Notification's sms channel.
 */
class SmsReminder implements ReminderTypeInterface
{
    public const CODE = 'cart_reminder_sms';

    /** A product name in the text is cut to this many characters (an SMS segment is 160). */
    private const PRODUCT_LENGTH = 40;

    public function __construct(
        private readonly Config $config,
        private readonly SubscriptionPermissionInterface $permission,
        private readonly ReminderPolicyInterface $policy,
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly CartDataInterface $cartData,
        private readonly RestoreLinkInterface $restoreLinks,
        private readonly StoreManagerInterface $stores
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getChannel(): string
    {
        return Channel::SMS;
    }

    public function getPurpose(): string
    {
        return self::CODE;
    }

    /** The checkbox next to the number (Notification's address consent for this type). */
    public function getPermission(): ?SubscriptionPermissionInterface
    {
        return $this->permission;
    }

    public function getSubscriber(): ?SubscriberInterface
    {
        return null;
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->config->isEnabled($storeId);
    }

    public function getDelayMinutes(int $storeId): int
    {
        return $this->config->getDelayMinutes($storeId);
    }

    public function getMaxPerHour(): int
    {
        return $this->config->getMaxPerHour();
    }

    public function getPolicy(): ReminderPolicyInterface
    {
        return $this->policy;
    }

    /** The number the shopper subscribed for this cart (guest or customer), as Notification stored it (E.164). */
    public function getRecipient(int $quoteId): ?string
    {
        $subscription = $this->subscriptions->find(self::CODE, $quoteId)[0] ?? null;
        $recipient = $subscription !== null ? $this->subscriptions->getRecipient($subscription) : null;
        return $recipient !== null && $recipient['channel'] === Channel::SMS && $recipient['recipient'] !== ''
            ? $recipient['recipient'] : null;
    }

    public function createDelivery(ReminderMessage $message): Delivery
    {
        $storeId = $message->storeId;
        return Delivery::text($storeId, Channel::SMS, $message->recipient, function () use ($message, $storeId) {
            // inside the store's environment: prices and the link in the store's currency and URL
            $quote = ($message->quote)();
            $facts = $this->cartData->get($quote);
            return new DeliveryContent([], text: $this->text($storeId, [
                '{{store}}' => $this->storeName($storeId),
                '{{name}}' => $facts->firstName,
                '{{product}}' => $this->short((string)($facts->items[0]['name'] ?? '')),
                '{{total}}' => $facts->grandTotal,
                '{{link}}' => $this->restoreLinks->getUrl($quote, self::CODE),
            ]));
        }, true, $message->isRecipientCurrent, true, self::CODE);
    }

    /** The configured text with its placeholders filled; an empty name leaves no stray space or comma behind. */
    private function text(int $storeId, array $values): string
    {
        $text = strtr($this->config->getText($storeId), $values);
        $text = preg_replace(['/ +([,.!?])/u', '/[ \t]{2,}/u'], ['$1', ' '], $text);
        return trim((string)$text);
    }

    private function storeName(int $storeId): string
    {
        $name = $this->config->getStoreName($storeId);
        return $name !== '' ? $name : (string)$this->stores->getStore($storeId)->getFrontendName();
    }

    /** One line, at most PRODUCT_LENGTH characters ("..." - GSM-7, an ellipsis character would make it Unicode). */
    private function short(string $name): string
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        return mb_strlen($name) > self::PRODUCT_LENGTH
            ? rtrim(mb_substr($name, 0, self::PRODUCT_LENGTH - 3)) . '...' : $name;
    }
}
