<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Test\Unit\Model;

use CommerceKings\AbandonedCartSms\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path, $type = null, $store = null) => $values[$path] ?? null);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path, $type = null, $store = null) => (bool)($values[$path] ?? false));
        return new Config($scope);
    }

    public function testSettingsUnderAbandonedCartsSection(): void
    {
        $config = $this->config([
            'commercekings_abandoned_cart/sms/enabled' => '1',
            'commercekings_abandoned_cart/sms/delay_minutes' => '90',
            'commercekings_abandoned_cart/sms/max_per_hour' => '120',
            'commercekings_abandoned_cart/sms/text' => "  {{store}}: {{link}} Reply STOP to stop \n",
            'general/country/default' => 'RS',
            'general/store_information/name' => ' Acme ',
        ]);
        $this->assertSame([true, 90, 120, '{{store}}: {{link}} Reply STOP to stop', 'RS', 'Acme'], [$config->isEnabled(1),
            $config->getDelayMinutes(1), $config->getMaxPerHour(), $config->getText(1), $config->getDefaultCountry(1),
            $config->getStoreName(1)]);
    }

    public function testNeverBelowOneMinuteOrANegativeLimit(): void
    {
        $config = $this->config(['commercekings_abandoned_cart/sms/delay_minutes' => '0',
            'commercekings_abandoned_cart/sms/max_per_hour' => '-5']);
        $this->assertSame([false, 1, 0], [$config->isEnabled(1), $config->getDelayMinutes(1), $config->getMaxPerHour()]);
    }
}
