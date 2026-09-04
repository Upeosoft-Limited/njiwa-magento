<?php
/**
 * The Send test message button, and the box for the one number it goes to.
 *
 * The operator supplies the recipient and nothing else. The wording lives in
 * the controller, so this button cannot be used to send a message of somebody
 * else's choosing from the shop's own number.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Block\Adminhtml\System\Config;

use Magento\Framework\Phrase;

class TestMessage extends AbstractCheck
{
    protected function actionPath(): string
    {
        return 'njiwa/check/message';
    }

    protected function buttonLabel(): Phrase
    {
        return __('Send test message');
    }

    protected function needsNumber(): bool
    {
        return true;
    }
}
