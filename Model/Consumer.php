<?php
/**
 * The worker. Everything that talks to Njiwa happens here, after the customer
 * has been sent on their way.
 *
 * Magento's cron starts this consumer every minute, the same way it sends the
 * shop's own order emails, so a shop with working cron has nothing to set up.
 *
 * Nothing in here throws. A handler that throws marks its message as an error
 * in the queue and writes a stack trace where a merchant will never look, and
 * there is nothing a second attempt would fix that the first did not: the
 * claim below has already been taken, and Njiwa has already been asked.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Model;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Upeo\Njiwa\Exception\NjiwaException;
use Upeo\Njiwa\Model\ResourceModel\SendLog;

class Consumer
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var SendLog
     */
    private $sendLog;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        Config $config,
        Client $client,
        SendLog $sendLog,
        OrderRepositoryInterface $orderRepository,
        OrderFactory $orderFactory,
        StoreManagerInterface $storeManager,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->sendLog = $sendLog;
        $this->orderRepository = $orderRepository;
        $this->orderFactory = $orderFactory;
        $this->storeManager = $storeManager;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * One queued message. The payload is written by Notifier::queue().
     */
    public function process(string $payload): void
    {
        try {
            $this->send($this->read($payload));
        } catch (\Throwable $e) {
            $this->logger->error('Njiwa could not process a queued message: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function read(string $payload): array
    {
        $data = $this->json->unserialize($payload);
        if (!is_array($data) || !isset($data['event'], $data['increment_id'], $data['text'], $data['recipients'])) {
            throw new \InvalidArgumentException('A queued Njiwa message was not in a shape this version understands.');
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $job
     */
    private function send(array $job): void
    {
        $storeId = (int) ($job['store_id'] ?? 0);
        $event = (string) $job['event'];
        $incrementId = (string) $job['increment_id'];
        $text = (string) $job['text'];
        $recipients = is_array($job['recipients']) ? $job['recipients'] : [];

        // Read the settings again rather than trusting the ones that were in
        // force when this was queued. Somebody who turns the module off wants
        // it off now, not after the queue has drained.
        if (!$this->config->isEnabled($storeId)) {
            $this->logger->info(
                sprintf('Order %s: not sending the %s message, because sending is switched off.', $incrementId, $event)
            );
            return;
        }

        $order = $this->findOrder((int) ($job['order_id'] ?? 0), $incrementId, $storeId);
        $notes = [];

        foreach ($recipients as $recipient) {
            $recipient = preg_replace('/\D/', '', (string) $recipient) ?? '';
            if ($recipient === '') {
                continue;
            }

            $messageId = $this->sendLog->claim(
                $storeId,
                $incrementId,
                $event,
                $recipient,
                $order ? (int) $order->getEntityId() : null
            );

            if ($messageId === null) {
                // Somebody has been here already. This is how the same event
                // firing twice ends in one message.
                continue;
            }

            if ($order && empty($job['order_id'])) {
                $this->sendLog->attachOrderId($messageId, (int) $order->getEntityId());
            }

            $notes[] = $this->deliver($messageId, $recipient, $text, $event, $incrementId, $storeId);
        }

        if ($order !== null && $notes !== []) {
            $this->writeOnOrder($order, $notes);
        }
    }

    /**
     * @return string A line for the order's comment history.
     */
    private function deliver(
        int $messageId,
        string $recipient,
        string $text,
        string $event,
        string $incrementId,
        int $storeId
    ): string {
        try {
            $answer = $this->client->sendText($recipient, $text, $this->idempotencyKey($incrementId, $event, $recipient, $storeId), $storeId);

            $njiwaId = isset($answer['id']) ? (string) $answer['id'] : '';
            $this->sendLog->recordSent($messageId, $njiwaId);

            $note = (string) __('Njiwa: WhatsApp sent to +%1 (%2).', $recipient, $njiwaId !== '' ? $njiwaId : '?');
            if ($this->config->isTestKey($storeId)) {
                $note .= ' ' . (string) __('Test key, so nothing reached WhatsApp.');
            }

            $this->logger->info(sprintf('Order %s, %s: sent to +%s.', $incrementId, $event, $recipient));

            return $note;
        } catch (NjiwaException $e) {
            $this->sendLog->recordFailure($messageId, $e->getMessage());

            $this->logger->error(
                sprintf('Order %s, %s: could not message +%s. %s (%s)', $incrementId, $event, $recipient, $e->getMessage(), $e->getErrorCode())
            );

            return (string) __('Njiwa: could not WhatsApp +%1. %2', $recipient, $e->getMessage());
        }
    }

    /**
     * The order, if it can be found.
     *
     * It usually can. It is not required: the message was written when the
     * event fired and can go out whether or not the order is readable now,
     * and losing the note on the order is a smaller loss than losing the
     * message to the customer.
     */
    private function findOrder(int $orderId, string $incrementId, int $storeId): ?Order
    {
        if ($orderId > 0) {
            try {
                $order = $this->orderRepository->get($orderId);
                if ($order instanceof Order) {
                    return $order;
                }
            } catch (\Exception $e) {
                // Deleted, or on a shop where sales data lives elsewhere.
            }
        }

        try {
            $order = $this->orderFactory->create()->loadByIncrementIdAndStoreId($incrementId, (string) $storeId);
            if ($order->getEntityId()) {
                return $order;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not load order ' . $incrementId . ': ' . $e->getMessage());
        }

        return null;
    }

    /**
     * What went where, on the order itself, which is where a merchant asking
     * "was my customer told?" is already standing.
     *
     * @param string[] $notes
     */
    private function writeOnOrder(Order $order, array $notes): void
    {
        try {
            foreach ($notes as $note) {
                $order->addCommentToStatusHistory($note, false, false);
            }
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Sent, but could not write the note onto order ' . (string) $order->getIncrementId() . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * One key per store, order, event and recipient.
     *
     * Njiwa honours it for twenty-four hours, so a message delivered to this
     * worker twice replays the first answer instead of messaging the customer
     * again. The store's own address is folded in because a staging site
     * restored from production has the same order numbers as production, and
     * two shops sharing one Njiwa account must not silently answer for each
     * other's messages.
     */
    private function idempotencyKey(string $incrementId, string $event, string $recipient, int $storeId): string
    {
        return sprintf(
            'm2-%s-%s-%s-%s',
            substr(sha1($this->siteName($storeId)), 0, 8),
            $incrementId,
            $event,
            substr(sha1($recipient), 0, 6)
        );
    }

    private function siteName(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getBaseUrl();
        } catch (\Exception $e) {
            return 'magento';
        }
    }
}
