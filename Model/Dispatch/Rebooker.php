<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Dispatch;

use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Sales\Model\Order\Shipment;
use Shubo\Qshpr\Exception\DispatchException;
use Shubo\Qshpr\Model\Delivery\Booking;
use Shubo\Qshpr\Model\Delivery\ShipmentNotes;

/**
 * "Book courier again": a new Quickshipper delivery for a saved shipment
 * whose delivery was cancelled or failed. The Dispatcher books it and links
 * the new delivery to the shipment; this saves what a shipment save would
 * otherwise save — the new track and the order comment.
 */
class Rebooker
{
    /**
     * @param Dispatcher $dispatcher
     * @param ShipmentTrackRepositoryInterface $trackRepository
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param ShipmentNotes $shipmentNotes
     */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly ShipmentTrackRepositoryInterface $trackRepository,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly ShipmentNotes $shipmentNotes,
    ) {
    }

    /**
     * Book and record the new delivery.
     *
     * @param Shipment $shipment Saved.
     * @return Booking
     * @throws DispatchException
     */
    public function rebook(Shipment $shipment): Booking
    {
        $booking = $this->dispatcher->rebook($shipment);
        $shipmentId = (int)$shipment->getId();

        foreach ($shipment->getAllTracks() as $track) {
            if (!$track->getId()) {
                $track->setParentId($shipmentId);
                $track->setOrderId((int)$shipment->getOrderId());
                $this->trackRepository->save($track);
            }
        }

        foreach ($shipment->getOrder()->getStatusHistories() ?? [] as $history) {
            if (!$history->getEntityId()) {
                $this->historyRepository->save($history);
            }
        }

        $this->shipmentNotes->add($shipmentId, __(
            'Quickshipper: courier booked again, order %1 (%2).',
            $booking->qshprOrderNo,
            $booking->providerName
        ));

        return $booking;
    }
}
