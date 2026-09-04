<?php
/**
 * Turning what a customer typed into a number WhatsApp can reach.
 *
 * People write their number the way they say it: 0712 345 678, (071) 234-5678,
 * +254 712 345 678. WhatsApp needs one form.
 *
 * Unlike WooCommerce, Magento does not ship a list of international dialling
 * codes, and inventing one here would mean shipping a copy of a list that goes
 * stale in a module that has no business owning it. It is not needed either: a
 * recipient is read against the sending number's own country, so a local
 * number with its leading zero reaches the same phone. What this does is
 * remove the punctuation people type and refuse anything that is not a
 * telephone number at all.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Model;

class Numbers
{
    /**
     * Short enough to accept a national number anywhere, long enough that a
     * house number or an order reference typed into the phone field is not
     * mistaken for one.
     */
    private const MINIMUM_DIGITS = 7;

    /**
     * @param string|null $phone As the customer typed it.
     * @return string Digits only, or '' when there is nothing usable. A
     *                customer with no phone number is normal, so '' is an
     *                answer rather than an error.
     */
    public function toMsisdn(?string $phone): string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return '';
        }

        // A WhatsApp group is addressed as something like 12036304@g.us, and
        // Njiwa will post to it. One saved settings page could then message
        // every person in a group from the shop's own number, so anything
        // carrying an @ is refused outright rather than having its digits
        // picked out and sent somewhere unintended.
        if (strpos($raw, '@') !== false) {
            return '';
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';

        // 00 is how much of the world dials out. It means the same thing as a
        // leading +, and Njiwa wants neither.
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) < self::MINIMUM_DIGITS) {
            return '';
        }

        return $digits;
    }

    /**
     * A list typed by the shop owner: separated by commas, semicolons or
     * newlines, because people use all three and none of them is wrong.
     *
     * @return string[]
     */
    public function parseList(?string $raw): array
    {
        $numbers = [];

        foreach (preg_split('/[\s,;]+/', (string) $raw) ?: [] as $piece) {
            $number = $this->toMsisdn($piece);
            if ($number !== '') {
                $numbers[] = $number;
            }
        }

        return array_values(array_unique($numbers));
    }
}
