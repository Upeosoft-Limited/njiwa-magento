<?php
/**
 * The file the module writes to.
 *
 * It is a subclass rather than a virtual type with a fileName argument
 * because that argument only exists on newer 2.4 releases, and a module that
 * fails to start on 2.4.2 for the sake of saving one small file is not worth
 * the saving.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/njiwa.log';

    /**
     * Info and above. What was sent, what was refused and why, and nothing
     * about the ordinary working of a shop that already has enough logs.
     *
     * @var int
     */
    protected $loggerType = Logger::INFO;
}
