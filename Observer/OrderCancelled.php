<?php
/**
 * order_cancel_after: the order will not be going ahead.
 *
 * Magento dispatches this from Order::cancel(), after the cancellation has
 * been registered on the order, so the status the message quotes is the one
 * the customer will see. It fires whether the cancellation came from the
 * admin, from the customer, or from a payment that was never completed.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Upeo\Njiwa\Model\Config;

class OrderCancelled extends NotifyObserver
{
    protected function event(): string
    {
        return Config::EVENT_CANCELLED;
    }

    protected function order(Observer $observer): ?Order
    {
        $order = $observer->getEvent()->getData('order');

        return $order instanceof Order ? $order : null;
    }
}
