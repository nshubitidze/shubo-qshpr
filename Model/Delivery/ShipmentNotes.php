<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Delivery;

use Magento\Framework\Phrase;
use Magento\Sales\Api\ShipmentCommentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment\CommentFactory;
use Psr\Log\LoggerInterface;

/**
 * Adds a note for the merchant to a saved shipment (not shown to the
 * customer, no email).
 */
class ShipmentNotes
{
    /**
     * @param CommentFactory $commentFactory
     * @param ShipmentCommentRepositoryInterface $commentRepository
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly CommentFactory $commentFactory,
        private readonly ShipmentCommentRepositoryInterface $commentRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Add the note; a failure is logged, not thrown.
     *
     * @param int $shipmentId
     * @param Phrase $note
     * @return void
     */
    public function add(int $shipmentId, Phrase $note): void
    {
        try {
            $comment = $this->commentFactory->create();
            $comment->setParentId($shipmentId);
            $comment->setComment($note->render());
            $comment->setIsCustomerNotified(0);
            $comment->setIsVisibleOnFront(0);
            $this->commentRepository->save($comment);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'could not add "%s" to shipment %d: %s',
                $note->render(),
                $shipmentId,
                $e->getMessage()
            ));
        }
    }
}
