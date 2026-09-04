<?php
/**
 * Who hears about what, and when.
 *
 * The five observers all end up here. This class decides whether there is a
 * message to send at all, writes it while the order is in front of it, and
 * hands it to the queue. It never sends anything itself, because everything
 * that calls it is running inside somebody's checkout.
 *
 * The message is finished here rather than in the worker on purpose.
 * sales_order_place_after fires before the order row is written, so a worker
 * told only "order 000000123, placed" could go looking for an order that is
 * still a few milliseconds away from existing. Rendering now also means the
 * message says what was true at the moment it happened, rather than what
 * happens to be true a minute later.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Model;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

class Notifier
{
    public const TOPIC = 'upeo.njiwa.send';

    /** The shape of the payload, so an old message left in the queue across an upgrade can be recognised. */
    private const PAYLOAD_VERSION = 1;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Templates
     */
    private $templates;

    /**
     * @var Numbers
     */
    private $numbers;

    /**
     * @var PublisherInterface
     */
    private $publisher;

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
        Templates $templates,
        Numbers $numbers,
        PublisherInterface $publisher,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->templates = $templates;
        $this->numbers = $numbers;
        $this->publisher = $publisher;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Something has happened to an order. Work out what, if anything, that
     * means for WhatsApp.
     */
    public function orderEvent(Order $order, string $event): void
    {
        $storeId = (int) $order->getStoreId();

        if (!$this->config->isEnabled($storeId) || !$this->config->isConfigured($storeId)) {
            return;
        }

        if (in_array($event, Config::CUSTOMER_EVENTS, true)) {
            $this->tellTheCustomer($order, $event, $storeId);
        }

        // The shop owner hears once, when the order is placed. In Magento
        // that is the first moment an order is real: the row before it is a
        // quote, and a quote is somebody who reached the payment page and may
        // never come back.
        if ($event === Config::EVENT_PLACED) {
            $this->tellTheShop($order, $storeId);
        }
    }

    private function tellTheCustomer(Order $order, string $event, int $storeId): void
    {
        if (!$this->config->isEventOn($event, $storeId)) {
            return;
        }

        $number = $this->customerNumber($order);
        if ($number === '') {
            // A customer without a usable phone number is ordinary, not an
            // error. Nothing is sent and nothing is complained about.
            return;
        }

        $this->queue($order, $event, [$number], $storeId);
    }

    private function tellTheShop(Order $order, int $storeId): void
    {
        if (!$this->config->isEventOn(Config::EVENT_ALERT, $storeId)) {
            return;
        }

        $numbers = $this->numbers->parseList($this->config->alertNumbers($storeId));
        if ($numbers === []) {
            return;
        }

        $this->queue($order, Config::EVENT_ALERT, $numbers, $storeId);
    }

    /**
     * @param string[] $recipients
     */
    private function queue(Order $order, string $event, array $recipients, int $storeId): void
    {
        try {
            $template = $this->config->template($event, $storeId);
            if ($template === '') {
                // Clearing the wording is how a merchant switches one message
                // off without touching the switch above it.
                return;
            }

            $message = $this->templates->render($template, $order);
            if ($message === '') {
                $this->logger->warning(
                    sprintf('The wording for "%s" came out empty, so order %s sent nothing.', $event, (string) $order->getIncrementId())
                );
                return;
            }

            $this->publisher->publish(self::TOPIC, $this->json->serialize([
                'version' => self::PAYLOAD_VERSION,
                'event' => $event,
                'increment_id' => (string) $order->getIncrementId(),
                'order_id' => (int) $order->getEntityId(),
                'store_id' => $storeId,
                'recipients' => array_values($recipients),
                'text' => $message,
            ]));
        } catch (\Throwable $e) {
            // An order must never fail because a message could not be
            // arranged. This is the last catch before the checkout, and the
            // observers hold one more behind it.
            $this->logger->error(
                sprintf(
                    'Could not queue the %s message for order %s: %s',
                    $event,
                    (string) $order->getIncrementId(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }
    }

    /**
     * The number the customer gave. Billing first, because that is the one a
     * shop rings about a payment; shipping second, because a gift order often
     * has the only reachable number there.
     */
    private function customerNumber(Order $order): string
    {
        // getBillingAddress() answers null on newer Magento and false on
        // older ones, so both are tested for at once rather than by name.
        $billing = $order->getBillingAddress();
        if ($billing) {
            $number = $this->numbers->toMsisdn((string) $billing->getTelephone());
            if ($number !== '') {
                return $number;
            }
        }

        $shipping = $order->getShippingAddress();
        if ($shipping) {
            return $this->numbers->toMsisdn((string) $shipping->getTelephone());
        }

        return '';
    }
}
