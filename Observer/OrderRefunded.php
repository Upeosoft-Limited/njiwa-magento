<?php
/**
 * sales_order_creditmemo_save_after: the money is going back.
 *
 * A credit memo is how Magento records a refund, online or off. As with
 * shipments it can be saved more than once, and an order can have several
 * credit memos, so the customer is told once, on the first one.
 *
 * Worth knowing before switching this on: {order_total} is the total of the
 * order, not the amount refunded, so the wording that ships with this module
 * is right for a full refund and overstates a partial one. A shop that
 * refunds part of an order should say so in the wording instead of quoting a
 * figure. There is no placeholder for the refunded amount because a message
 * sent on the first credit memo cannot honestly total up the ones that have
 * not happened yet.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Upeo\Njiwa\Model\Config;

class OrderRefunded extends NotifyObserver
{
    protected function event(): string
    {
        return Config::EVENT_REFUNDED;
    }

    protected function order(Observer $observer): ?Order
    {
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        if (!$creditmemo instanceof Creditmemo) {
            return null;
        }

        $order = $creditmemo->getOrder();

        return $order instanceof Order ? $order : null;
    }
}
