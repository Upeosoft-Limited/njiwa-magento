<?php
/**
 * What every one of the five observers has in common.
 *
 * The whole of an observer's body is inside one try. sales_order_place_after
 * fires in the middle of somebody's checkout, and an exception thrown from
 * here would come out of the checkout as a failed order. A shop must not lose
 * a sale because WhatsApp, or this module, was having a bad afternoon, so
 * everything that goes wrong is written to var/log/njiwa.log and the order
 * carries on as though this module were not installed.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Upeo\Njiwa\Model\Notifier;

abstract class NotifyObserver implements ObserverInterface
{
    /**
     * @var Notifier
     */
    private $notifier;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(Notifier $notifier, LoggerInterface $logger)
    {
        $this->notifier = $notifier;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            $order = $this->order($observer);
            if ($order instanceof Order) {
                $this->notifier->orderEvent($order, $this->event());
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Njiwa could not handle the %s event: %s', $this->event(), $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * Which of the events in Upeo\Njiwa\Model\Config this observer is for.
     */
    abstract protected function event(): string;

    /**
     * The order the event is about. Magento hands each of these five events a
     * different object, and only some of them are the order itself.
     *
     * @return Order|null
     */
    abstract protected function order(Observer $observer): ?Order;
}
