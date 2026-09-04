<?php
/**
 * The Test connection button. It sends nothing: it asks Njiwa which numbers
 * the saved key can send from, which is the question a merchant has as soon
 * as they have pasted one.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Block\Adminhtml\System\Config;

use Magento\Framework\Phrase;

class TestConnection extends AbstractCheck
{
    protected function actionPath(): string
    {
        return 'njiwa/check/connection';
    }

    protected function buttonLabel(): Phrase
    {
        return __('Test connection');
    }
}
