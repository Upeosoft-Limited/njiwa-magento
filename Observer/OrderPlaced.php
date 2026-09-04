<?php
/**
 * sales_order_place_after: the order has been accepted.
 *
 * This is the moment an order becomes real in Magento. It fires once, inside
 * Order::place(), before the order row has been written, which is why nothing
 * downstream of here depends on the order having a row id yet.
 *
 * It is also the only event that tells the shop owner, because everything
 * before it is a quote and a quote is somebody who reached the payment page.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Upeo\Njiwa\Model\Config;

class OrderPlaced extends NotifyObserver
{
    protected function event(): string
    {
        return Config::EVENT_PLACED;
    }

    protected function order(Observer $observer): ?Order
    {
        $order = $observer->getEvent()->getData('order');

        return $order instanceof Order ? $order : null;
    }
}
