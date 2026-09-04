<?php
/**
 * sales_order_invoice_pay: the money has arrived.
 *
 * Magento dispatches this from Invoice::pay(), which happens when a payment
 * is captured or when somebody creates an invoice for an offline method. It
 * is the payment being recorded, not the order being edited, which is why the
 * customer can be told about it without any further checking.
 *
 * A shop taking payment at the checkout will see this and "order placed" fire
 * within a second of each other. That is why both are switched off until a
 * merchant chooses, and why most shops want this one rather than both.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Observer;

use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Upeo\Njiwa\Model\Config;

class PaymentReceived extends NotifyObserver
{
    protected function event(): string
    {
        return Config::EVENT_PAID;
    }

    protected function order(Observer $observer): ?Order
    {
        $invoice = $observer->getEvent()->getData('invoice');
        if (!$invoice instanceof Invoice) {
            return null;
        }

        $order = $invoice->getOrder();

        return $order instanceof Order ? $order : null;
    }
}
