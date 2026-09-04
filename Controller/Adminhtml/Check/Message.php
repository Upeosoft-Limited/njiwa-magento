<?php
/**
 * Send test message: one real message, to one number the operator types.
 *
 * The wording is fixed in this file. The operator supplies the recipient and
 * nothing else, so this button cannot become a way of sending arbitrary
 * WhatsApp messages from the shop's own number.
 *
 * POST only. A button that spends money and puts a message on somebody's phone
 * must not be reachable by following a link, and Magento checks the form key
 * on every admin POST, so a page on another site cannot press it either.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Controller\Adminhtml\Check;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Upeo\Njiwa\Controller\Adminhtml\AbstractCheck;
use Upeo\Njiwa\Exception\NjiwaException;
use Upeo\Njiwa\Model\Client;
use Upeo\Njiwa\Model\Config;
use Upeo\Njiwa\Model\Numbers;

class Message extends AbstractCheck
{
    /**
     * Long enough that a held-down key, or a double click that got past the
     * disabled button, cannot put a row of messages on somebody's phone.
     */
    private const PAUSE_SECONDS = 10;

    private const PAUSE_KEY = 'upeo_njiwa_test_message_sent';

    /**
     * @var Numbers
     */
    private $numbers;

    /**
     * @var CacheInterface
     */
    private $cache;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Client $client,
        Config $config,
        StoreManagerInterface $storeManager,
        Numbers $numbers,
        CacheInterface $cache
    ) {
        parent::__construct($context, $resultJsonFactory, $client, $config, $storeManager);
        $this->numbers = $numbers;
        $this->cache = $cache;
    }

    public function execute(): ResultInterface
    {
        $storeId = $this->scopeStoreId();
        $typed = $this->param('number');

        // A WhatsApp group is addressed as something like 12036304@g.us, and
        // Njiwa will post to it. One press of this button could then message
        // hundreds of people from the shop's own number.
        if (strpos($typed, '@') !== false) {
            return $this->reply(
                false,
                (string) __('That is a WhatsApp group, not a phone number, and this module never posts to a group. Type one phone number.')
            );
        }

        $to = $this->numbers->toMsisdn($typed);
        if ($to === '') {
            return $this->reply(
                false,
                (string) __('Type the number this test should go to, in full international form such as 254712345678. Use your own number: this sends a real message.')
            );
        }

        if ($this->cache->load(self::PAUSE_KEY)) {
            return $this->reply(
                false,
                (string) __('A test message went out a few seconds ago. Wait a moment before sending another.')
            );
        }

        try {
            $answer = $this->client->sendText($to, $this->text($storeId), '', $storeId);
        } catch (NjiwaException $e) {
            return $this->reply(false, $this->explain($e));
        }

        // Only after Njiwa accepted it. A refusal costs nothing and there is
        // no reason to make somebody wait before correcting the setting that
        // caused it.
        $this->cache->save('1', self::PAUSE_KEY, [], self::PAUSE_SECONDS);

        // Njiwa's own id for the message, which is what the console and the
        // support inbox both search by.
        $njiwaId = isset($answer['id']) && is_scalar($answer['id']) ? (string) $answer['id'] : '';

        $message = $njiwaId === ''
            ? (string) __('Sent to +%1.', $to)
            : (string) __('Sent to +%1 (%2).', $to, $njiwaId);

        if ($this->config->isTestKey($storeId)) {
            $message .= ' ' . (string) __('This is a test key, so nothing actually reached the phone.');
        }

        return $this->reply(true, $message);
    }

    /**
     * The message itself. It names the shop, because a phone that receives it
     * shows a number rather than a name, and says what it proves.
     */
    private function text(?int $storeId): string
    {
        $shop = $this->shopName($storeId);

        if ($shop === '') {
            return (string) __('Test message from your Magento shop. If you can read this, Magento can reach your customers on WhatsApp.');
        }

        return (string) __('Test message from %1. If you can read this, Magento can reach your customers on WhatsApp.', $shop);
    }
}
