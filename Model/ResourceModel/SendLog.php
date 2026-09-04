<?php
/**
 * The record of what has been sent, and the thing that stops it twice.
 *
 * Every send is claimed here before it is attempted. The claim is an insert
 * against a unique key of store, order number, event and recipient, so two
 * workers racing on the same message, or the same Magento event firing twice
 * as a shipment is saved a second time to add a tracking number, end with one
 * message rather than two. The second one loses the race and stops.
 *
 * The row outlives the send. Njiwa's Idempotency-Key covers twenty-four
 * hours; this covers the order.
 */

declare(strict_types=1);

namespace Upeo\Njiwa\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Psr\Log\LoggerInterface;

class SendLog
{
    public const TABLE = 'upeo_njiwa_message';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ResourceConnection $resource, LoggerInterface $logger)
    {
        $this->resource = $resource;
        $this->logger = $logger;
    }

    /**
     * Claim one send.
     *
     * @return int|null The row id when this caller may go ahead, or null when
     *                  somebody has already sent this message and it must not
     *                  be sent again.
     */
    public function claim(
        int $storeId,
        string $incrementId,
        string $event,
        string $recipient,
        ?int $orderId = null
    ): ?int {
        $connection = $this->resource->getConnection();

        try {
            $connection->insert(
                $this->resource->getTableName(self::TABLE),
                [
                    'order_id' => $orderId ?: null,
                    'increment_id' => $incrementId,
                    'store_id' => $storeId,
                    'event' => $event,
                    'recipient' => $recipient,
                    'status' => self::STATUS_QUEUED,
                ]
            );
        } catch (DuplicateException $e) {
            // Already claimed. This is the ordinary outcome of an event that
            // fires twice and is not worth a line in the log.
            return null;
        } catch (\Exception $e) {
            // Anything else is the database being unwell. Refusing to send is
            // the safe answer: a message that goes out twice cannot be taken
            // back, and one that does not go out leaves this line behind.
            $this->logger->error(
                sprintf(
                    'Could not claim the %s message for order %s: %s',
                    $event,
                    $incrementId,
                    $e->getMessage()
                )
            );
            return null;
        }

        return (int) $connection->lastInsertId($this->resource->getTableName(self::TABLE));
    }

    /**
     * Njiwa accepted it. The id it gave back is what support looks the
     * message up by.
     */
    public function recordSent(int $messageId, string $njiwaId): void
    {
        $this->update($messageId, ['status' => self::STATUS_SENT, 'njiwa_id' => $njiwaId]);
    }

    /**
     * Njiwa refused it, or could not be reached. The claim stays, so this is
     * not tried again on its own; the reason is written on the order as well
     * as here, because that is where the merchant is looking.
     */
    public function recordFailure(int $messageId, string $reason): void
    {
        $this->update($messageId, [
            'status' => self::STATUS_FAILED,
            'detail' => mb_substr($reason, 0, 255),
        ]);
    }

    /**
     * Fill in the order's row id once the order has one. At the moment an
     * order is placed it does not, and this is the only thing that ties these
     * rows back to sales_order for anybody reading the table by hand.
     */
    public function attachOrderId(int $messageId, int $orderId): void
    {
        $this->update($messageId, ['order_id' => $orderId]);
    }

    /**
     * @param array<string,mixed> $values
     */
    private function update(int $messageId, array $values): void
    {
        try {
            $this->resource->getConnection()->update(
                $this->resource->getTableName(self::TABLE),
                $values,
                ['message_id = ?' => $messageId]
            );
        } catch (\Exception $e) {
            // The message itself has already been sent or refused by now.
            // Losing the note of it is not worth throwing over.
            $this->logger->error('Could not record the outcome of message ' . $messageId . ': ' . $e->getMessage());
        }
    }
}
