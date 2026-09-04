<?php
/**
 * Anything Njiwa refused, or could not be asked.
 *
 * getErrorCode() is the stable, machine readable reason and is the thing to
 * branch on. The wording of the message can change; the code does not.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class NjiwaException extends LocalizedException
{
    /**
     * @var string
     */
    private $errorCode;

    /**
     * @var int
     */
    private $status;

    /**
     * @var string|null
     */
    private $docs;

    /**
     * @param Phrase $phrase
     * @param string $errorCode Njiwa's own code, or one of this module's.
     * @param int $status The HTTP status, where there was one.
     * @param string|null $docs A page explaining that exact code.
     * @param \Exception|null $cause
     */
    public function __construct(
        Phrase $phrase,
        string $errorCode = 'unknown',
        int $status = 0,
        ?string $docs = null,
        ?\Exception $cause = null
    ) {
        parent::__construct($phrase, $cause);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->docs = $docs;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getDocs(): ?string
    {
        return $this->docs;
    }
}
