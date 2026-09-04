<?php
/**
 * The message itself.
 *
 * A template is plain text with placeholders in braces. Every placeholder a
 * shop can use is listed in placeholders() below, and that same list is what
 * the settings page prints, so what a merchant reads there cannot drift from
 * what this class replaces.
 *
 * There is deliberately no {order_url} and no {admin_url}. Magento cannot
 * produce either honestly: the customer's own order page needs a logged in
 * customer, which a guest checkout does not have, and an admin URL carries a
 * secret key tied to the session of the person who generated it, so one built
 * by a queue worker would land the merchant on a login screen and an error.
 * A link that does not work is worse than no link.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Templates
{
    /** WhatsApp takes 4096 characters. Stopping short leaves room to spare. */
    private const MAX_LENGTH = 4000;

    /** How many order lines {items} prints before it starts counting instead. */
    private const MAX_ITEMS = 10;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(TimezoneInterface $timezone, LoggerInterface $logger)
    {
        $this->timezone = $timezone;
        $this->logger = $logger;
    }

    /**
     * Placeholder => what it is replaced with, in the shop's own words.
     *
     * @return array<string,string>
     */
    public static function placeholders(): array
    {
        return [
            '{first_name}' => (string) __('The customer\'s first name, or "there" if the order has none.'),
            '{last_name}' => (string) __('The customer\'s last name.'),
            '{customer_name}' => (string) __('Both names together.'),
            '{order_number}' => (string) __('The order number as the customer sees it.'),
            '{order_total}' => (string) __('The order total, in the currency the order was placed in.'),
            '{order_date}' => (string) __('The date the order was placed, in the store\'s own timezone.'),
            '{order_status}' => (string) __('The status the order is in at that moment.'),
            '{payment_method}' => (string) __('How they paid, as it is titled in your payment settings.'),
            '{items}' => (string) __('One line per item, as "2 x Blue shirt".'),
            '{item_count}' => (string) __('How many items in total.'),
            '{shop_name}' => (string) __('The store name, from Stores, Configuration, General, Store Information.'),
        ];
    }

    /**
     * @return string The message, or '' when the template is empty.
     */
    public function render(string $template, Order $order): string
    {
        $template = trim($template);
        if ($template === '') {
            return '';
        }

        $message = strtr($template, $this->values($order));

        // Anything still in braces is a placeholder that does not exist,
        // usually a typo. Sending "{order_no}" to a customer looks broken, so
        // it comes out and the shop is told where to look.
        if (preg_match_all('/\{[a-z_]+\}/', $message, $found)) {
            $this->logger->warning(
                sprintf(
                    'Unknown placeholder %s in the wording for order %s. It was removed before sending.',
                    implode(', ', array_unique($found[0])),
                    (string) $order->getIncrementId()
                )
            );
            $message = (string) preg_replace('/\{[a-z_]+\}/', '', $message);
        }

        $message = trim((string) preg_replace('/\n{3,}/', "\n\n", $message));

        if (mb_strlen($message) > self::MAX_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_LENGTH - 1) . "\u{2026}";
        }

        return $message;
    }

    /**
     * @return array<string,string>
     */
    private function values(Order $order): array
    {
        $billing = $order->getBillingAddress();

        // The customer fields are empty on a guest order, where the only name
        // the shop has is the one on the billing address.
        $first = trim((string) ($order->getCustomerFirstname() ?: ($billing ? $billing->getFirstname() : '')));
        $last = trim((string) ($order->getCustomerLastname() ?: ($billing ? $billing->getLastname() : '')));

        return [
            '{first_name}' => $first !== '' ? $first : (string) __('there'),
            '{last_name}' => $last,
            '{customer_name}' => trim($first . ' ' . $last),
            '{order_number}' => (string) $order->getIncrementId(),
            '{order_total}' => $this->total($order),
            '{order_date}' => $this->placedOn($order),
            '{order_status}' => (string) $order->getStatusLabel(),
            '{payment_method}' => $this->paymentTitle($order),
            '{items}' => $this->items($order),
            '{item_count}' => (string) (int) $order->getTotalQtyOrdered(),
            '{shop_name}' => $this->shopName($order),
        ];
    }

    /**
     * The total in the currency the customer was charged in, formatted the
     * way that currency is written rather than the way the admin's own
     * currency is.
     */
    private function total(Order $order): string
    {
        try {
            return trim((string) $order->getOrderCurrency()->formatTxt((float) $order->getGrandTotal()));
        } catch (\Exception $e) {
            // A currency that is no longer installed should cost the shop a
            // symbol, not the whole message.
            return sprintf('%.2f', (float) $order->getGrandTotal());
        }
    }

    /**
     * created_at is stored in UTC. A customer reading "placed at 03:00"
     * because nobody converted it is a support call, so the store's own
     * timezone is used even though the worker that renders this runs outside
     * any store.
     */
    private function placedOn(Order $order): string
    {
        $createdAt = (string) $order->getCreatedAt();
        if ($createdAt === '') {
            return '';
        }

        try {
            return $this->timezone->formatDateTime(
                new \DateTime($createdAt, new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT,
                null,
                $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (string) $order->getStoreId())
            );
        } catch (\Exception $e) {
            return $createdAt;
        }
    }

    /**
     * The title the merchant gave the payment method, which is what the
     * customer saw at the checkout.
     *
     * getMethodInstance() throws for a method that has since been switched
     * off or uninstalled, and an old order paid by a method the shop no
     * longer offers is completely ordinary, so the answer falls back to what
     * was recorded on the payment at the time and then to its code.
     */
    private function paymentTitle(Order $order): string
    {
        // An order with no payment record at all is rare but possible, and
        // older Magento answers false here where newer answers null.
        $payment = $order->getPayment();
        if (!$payment) {
            return '';
        }

        try {
            $title = (string) $payment->getMethodInstance()->getTitle();
            if ($title !== '') {
                return $title;
            }
        } catch (\Exception $e) {
            // Falls through to what the order itself remembers.
        }

        $recorded = $payment->getAdditionalInformation('method_title');
        if (is_string($recorded) && $recorded !== '') {
            return $recorded;
        }

        return (string) $payment->getMethod();
    }

    private function items(Order $order): string
    {
        $lines = [];
        $more = 0;

        foreach ($order->getAllVisibleItems() as $item) {
            if (count($lines) >= self::MAX_ITEMS) {
                ++$more;
                continue;
            }
            $lines[] = sprintf('%d x %s', (int) $item->getQtyOrdered(), (string) $item->getName());
        }

        if ($more > 0) {
            $lines[] = (string) __('and %1 more', $more);
        }

        return implode("\n", $lines);
    }

    private function shopName(Order $order): string
    {
        try {
            return (string) $order->getStore()->getFrontendName();
        } catch (\Exception $e) {
            return '';
        }
    }
}
