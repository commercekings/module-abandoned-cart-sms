<?php
declare(strict_types=1);

namespace CommerceKings\AbandonedCartSms\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The module plugs into Abandoned Cart and Notification through their stable extension points only (docs/extending.md
 * of module-abandoned-cart): every class of theirs it names - in PHP and in di.xml - is @api, and the type is wired
 * the way the guide says, with its cart data and restore link through proxies.
 */
class WiringTest extends TestCase
{
    private static function di(): \SimpleXMLElement
    {
        return simplexml_load_file(__DIR__ . '/../../etc/di.xml');
    }

    public function testTheTypeIsRegisteredInAllThreePools(): void
    {
        $di = self::di();
        $item = fn (string $type) => (string)($di->xpath(sprintf('//type[@name="%s"]/arguments/argument[@name="types"]'
            . '/item[@name="cart_reminder_sms"]', $type))[0] ?? '');
        $this->assertSame('CommerceKings\AbandonedCartSms\Model\SmsReminder',
            $item('CommerceKings\AbandonedCart\Model\ReminderTypePool'));
        $reminder = $item('CommerceKings\Notification\Model\TypePool');
        $subscription = $item('CommerceKings\Notification\Model\SubscriptionTypePool');
        $virtual = fn (string $name) => $di->xpath(sprintf('//virtualType[@name="%s"]', $name))[0] ?? null;
        $this->assertSame('CommerceKings\AbandonedCart\Model\Notification\CartReminder', (string)$virtual($reminder)['type']);
        $this->assertSame('CommerceKings\AbandonedCart\Model\Notification\CartSubscription',
            (string)$virtual($subscription)['type']);
        foreach ([$reminder, $subscription] as $name) {
            $this->assertSame('CommerceKings\AbandonedCartSms\Model\SmsReminder',
                (string)$virtual($name)->xpath('arguments/argument[@name="type"]')[0]);
        }
    }

    /** Injected directly, both close a construction cycle through Notification's subscription type pool. */
    public function testCartDataAndRestoreLinkComeThroughProxies(): void
    {
        $arguments = [];
        foreach (self::di()->xpath('//type[@name="CommerceKings\AbandonedCartSms\Model\SmsReminder"]/arguments/argument') as $argument) {
            $arguments[(string)$argument['name']] = (string)$argument;
        }
        $this->assertSame('CommerceKings\AbandonedCart\Api\CartDataInterface\Proxy', $arguments['cartData']);
        $this->assertSame('CommerceKings\AbandonedCart\Api\RestoreLinkInterface\Proxy', $arguments['restoreLinks']);
        $consent = self::di()->xpath(sprintf('//virtualType[@name="%s"]', $arguments['permission']))[0];
        $this->assertSame('CommerceKings\Notification\Model\AddressConsent', (string)$consent['type']);
        $this->assertSame('cart_reminder_sms', (string)$consent->xpath('arguments/argument[@name="typeCode"]')[0]);
    }

    /** @return string[] CommerceKings classes of other modules that this module names */
    private static function foreignClasses(): array
    {
        $root = dirname(__DIR__, 2);
        $sources = [];
        foreach (['Model', 'ViewModel', 'etc', 'view'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                if ($file->isFile() && preg_match('/\.(php|phtml|xml)$/', $file->getFilename())) {
                    $sources[] = file_get_contents($file->getPathname());
                }
            }
        }
        preg_match_all('/CommerceKings\\\\(?:AbandonedCart|Notification)\\\\[A-Za-z\\\\]+/', implode("\n", $sources), $matches);
        return array_values(array_unique(array_map(fn ($class) => preg_replace('/\\\\Proxy$/', '', $class),
            array_filter($matches[0], fn ($class) => !str_starts_with($class, 'CommerceKings\AbandonedCartSms')))));
    }

    public function testEveryClassOfAbandonedCartAndNotificationItUsesIsApi(): void
    {
        $classes = self::foreignClasses();
        $this->assertNotEmpty($classes);
        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class) || interface_exists($class), $class . ' exists');
            $this->assertStringContainsString('@api', (string)(new \ReflectionClass($class))->getDocComment(),
                $class . ' is a stable extension point');
        }
    }
}
