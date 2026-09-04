<?php
/**
 * Every setting this module has, read at the scope the order belongs to.
 *
 * One Magento installation can run several stores, each with its own name,
 * currency and language, and there is no reason a merchant should not give
 * each of them its own Njiwa account and its own wording. Nothing here reads
 * configuration without a store id, so a message is always composed with the
 * settings of the store the order was placed in rather than with whichever
 * store happened to be current when the queue worker woke up.
 *
 * The paths match etc/config.xml and etc/adminhtml/system.xml exactly. A
 * setting that is read from a path nothing writes to is a setting that is
 * always at its default and nobody can tell why.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const DEFAULT_BASE_URL = 'https://njiwa.upeo.ai';

    /**
     * The five moments a customer hears about, in the order they happen to an
     * order. The name of each one is half of its configuration path and the
     * whole of what is written in the "event" column of upeo_njiwa_message,
     * so these strings are not free to change.
     */
    public const EVENT_PLACED = 'placed';
    public const EVENT_PAID = 'paid';
    public const EVENT_SHIPPED = 'shipped';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_REFUNDED = 'refunded';

    /** The one message that goes to the shop rather than to the customer. */
    public const EVENT_ALERT = 'alert';

    public const CUSTOMER_EVENTS = [
        self::EVENT_PLACED,
        self::EVENT_PAID,
        self::EVENT_SHIPPED,
        self::EVENT_CANCELLED,
        self::EVENT_REFUNDED,
    ];

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /**
     * The master switch. Off keeps every other setting and sends nothing.
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag('njiwa/connection/enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The API key, decrypted.
     *
     * system.xml stores it through Magento's encrypted backend model, so what
     * comes back from configuration is ciphertext and this is the only place
     * in the module that turns it back into a key.
     */
    public function apiKey(?int $storeId = null): string
    {
        $stored = (string) $this->scopeConfig->getValue(
            'njiwa/connection/api_key',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($stored === '') {
            return '';
        }

        return trim((string) $this->encryptor->decrypt($stored));
    }

    public function isConfigured(?int $storeId = null): bool
    {
        return $this->apiKey($storeId) !== '';
    }

    /**
     * A test key checks and stores every message and delivers nothing, which
     * is worth saying out loud wherever an outcome is reported.
     */
    public function isTestKey(?int $storeId = null): bool
    {
        return strpos($this->apiKey($storeId), 'sk_test_') === 0;
    }

    public function baseUrl(?int $storeId = null): string
    {
        $url = trim((string) $this->scopeConfig->getValue(
            'njiwa/connection/base_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        return rtrim($url === '' ? self::DEFAULT_BASE_URL : $url, '/');
    }

    /**
     * Which of the account's numbers sends. Empty means the one marked
     * default in the console, which is the right answer for the many shops
     * that have exactly one number and never think about this again.
     */
    public function from(?int $storeId = null): string
    {
        $from = (string) $this->scopeConfig->getValue(
            'njiwa/connection/from',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return preg_replace('/\D/', '', $from) ?? '';
    }

    public function isEventOn(string $event, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag($this->path($event, 'enabled'), ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The wording for one event. Empty is a decision, not a mistake: clearing
     * the box is how a merchant stops one message without touching the switch
     * above it, and nothing is sent for an event whose wording is empty.
     */
    public function template(string $event, ?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            $this->path($event, 'template'),
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * The shop's own numbers, as typed, for the new order alert.
     */
    public function alertNumbers(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            'njiwa/alert/numbers',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function path(string $event, string $suffix): string
    {
        if ($event === self::EVENT_ALERT) {
            return 'njiwa/alert/' . $suffix;
        }

        return 'njiwa/customer/' . $event . '_' . $suffix;
    }
}
