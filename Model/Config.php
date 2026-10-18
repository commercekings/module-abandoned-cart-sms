<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/** Stores > Configuration > CommerceKings > Abandoned Cart > Text message reminders. */
class Config
{
    private const PREFIX = 'commercekings_abandoned_cart/sms/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getDelayMinutes(int $storeId): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::PREFIX . 'delay_minutes', ScopeInterface::SCOPE_STORE,
            $storeId));
    }

    /** The SMS provider's limit: texts per hour across all stores; 0 = no limit. */
    public function getMaxPerHour(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::PREFIX . 'max_per_hour'));
    }

    /** The text with its placeholders ({{store}}, {{name}}, {{product}}, {{total}}, {{link}}). */
    public function getText(int $storeId): string
    {
        return trim((string)$this->scopeConfig->getValue(self::PREFIX . 'text', ScopeInterface::SCOPE_STORE, $storeId));
    }

    /** The country whose calling code a number without "+" gets at checkout (Magento's default country). */
    public function getDefaultCountry(int $storeId): string
    {
        return (string)$this->scopeConfig->getValue('general/country/default', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** The brand in the text: the store view's Store Information name; SmsReminder falls back to the frontend name. */
    public function getStoreName(int $storeId): string
    {
        return trim((string)$this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE,
            $storeId));
    }
}
