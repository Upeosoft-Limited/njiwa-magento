<?php
/**
 * Test connection: who this key belongs to, and what it can send from.
 *
 * It asks Njiwa for the account's numbers and sends nothing. That is the first
 * question a merchant has after pasting a key, and answering it here saves the
 * round trip of placing an order to find out that the key was pasted with a
 * space on the end.
 *
 * The key is encrypted in Magento's own configuration, so the browser never
 * holds it and the check has to happen on the server.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Controller\Adminhtml\Check;

use Magento\Framework\Controller\ResultInterface;
use Upeo\Njiwa\Controller\Adminhtml\AbstractCheck;
use Upeo\Njiwa\Exception\NjiwaException;

class Connection extends AbstractCheck
{
    public function execute(): ResultInterface
    {
        $storeId = $this->scopeStoreId();

        if (!$this->config->isConfigured($storeId)) {
            return $this->reply(
                false,
                (string) __('There is no API key saved for this scope yet. Paste one from the Njiwa console and save, then try again.')
            );
        }

        try {
            $numbers = $this->client->numbers($storeId);
        } catch (NjiwaException $e) {
            return $this->reply(false, $this->explain($e));
        }

        $lines = [];

        if ($this->config->isTestKey($storeId)) {
            $lines[] = (string) __(
                'This is a test key. Every message is checked and stored, and nothing reaches WhatsApp. Swap it for a key beginning sk_live_ when you are ready.'
            );
        }

        $known = [];
        $listed = [];

        foreach ($numbers as $number) {
            if (!is_array($number)) {
                continue;
            }

            $msisdn = preg_replace('/\D/', '', (string) ($number['msisdn'] ?? '')) ?? '';
            if ($msisdn !== '') {
                $known[] = $msisdn;
            }

            $listed[] = sprintf(
                '%s — %s (%s)',
                (string) ($number['label'] ?? ''),
                $msisdn === '' ? (string) __('not linked yet') : '+' . $msisdn,
                (string) ($number['status'] ?? '')
            );
        }

        if ($listed === []) {
            $lines[] = (string) __(
                'The key works, but this account has no numbers yet. Add one in the Njiwa console under Numbers and link it.'
            );
        } else {
            $lines[] = (string) __('Connected. This key can send from:') . "\n" . implode("\n", $listed);
        }

        // A Send from that names a number this account does not have is the
        // one settings mistake that looks fine on the page and refuses every
        // message afterwards, so it is worth saying here rather than in a log.
        $from = $this->config->from($storeId);
        if ($from !== '' && !in_array($from, $known, true)) {
            $lines[] = (string) __(
                'Send from is set to %1, which is not a number on this account, so every message will be refused. Correct it, or clear it to use the default number.',
                $from
            );
        }

        return $this->reply(true, implode("\n\n", $lines));
    }
}
