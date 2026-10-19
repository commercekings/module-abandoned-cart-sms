<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\ViewModel;

use CommerceKings\AbandonedCartSms\Model\Config;
use CommerceKings\AbandonedCartSms\Model\SmsReminder;
use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\ViewModel\AddressConsent;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Whether the checkout offers text reminders (Model\Checkout\SmsConsentLayout): only where they are on, an SMS provider
 * is installed and the store view has a checkbox text (Notifications > Consent for an address).
 */
class Checkout implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ProviderPoolInterface $providers,
        private readonly AddressConsent $addressConsent,
        private readonly StoreManagerInterface $stores,
        private readonly UrlInterface $url
    ) {
    }

    public function isAvailable(): bool
    {
        if (!$this->config->isEnabled($this->storeId()) || $this->addressConsent->getWording(Channel::SMS) === '') {
            return false;
        }
        foreach ($this->providers->getAll() as $provider) {
            if ($provider->getChannel() === Channel::SMS) {
                return true;
            }
        }
        return false;
    }

    public function getType(): string
    {
        return SmsReminder::CODE;
    }

    /**
     * @return array{type: string, country: string, urls: array{guest: string, mine: string}} for js/view/sms-consent
     */
    public function getOptions(): array
    {
        $base = rtrim($this->url->getBaseUrl(['_type' => UrlInterface::URL_TYPE_WEB]), '/') . '/rest/'
            . rawurlencode((string)$this->stores->getStore()->getCode()) . '/V1/ck-notification/subscriptions';
        return [
            'type' => SmsReminder::CODE,
            'country' => $this->config->getDefaultCountry($this->storeId()),
            'urls' => ['guest' => $base, 'mine' => $base . '/mine'],
        ];
    }

    private function storeId(): int
    {
        return (int)$this->stores->getStore()->getId();
    }
}
