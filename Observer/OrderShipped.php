<?php
/**
 * sales_order_shipment_save_after: it is on its way.
 *
 * This fires on every save of a shipment, and a shipment is saved again
 * whenever a tracking number or a comment is added to it, so it can arrive
 * several times for one parcel. The claim in upeo_njiwa_message is what turns
 * that into one message; nothing here tries to guess which save was the real
 * one.
 *
 * An order shipped in two parcels therefore messages the customer once, on
 * the first. Telling somebody twice that their order is on its way reads like
 * a mistake, and the second parcel is usually the shop's business rather than
 * the customer's.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Upeo\Njiwa\Model\Config;

class OrderShipped extends NotifyObserver
{
    protected function event(): string
    {
        return Config::EVENT_SHIPPED;
    }

    protected function order(Observer $observer): ?Order
    {
        $shipment = $observer->getEvent()->getData('shipment');
        if (!$shipment instanceof Shipment) {
            return null;
        }

        $order = $shipment->getOrder();

        return $order instanceof Order ? $order : null;
    }
}
