<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Dispatch;

use Laminas\Http\Response as HttpResponse;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Exception\DispatchException;
use Shubo\Qshpr\Exception\PickupNotConfiguredException;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\CashOnDelivery\CashOnDelivery;
use Shubo\Qshpr\Model\Delivery\Booking;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Geocoding\Address;
use Shubo\Qshpr\Model\Geocoding\Geocoder;
use Shubo\Qshpr\Model\Money;
use Shubo\Qshpr\Model\OrderStatus;
use Shubo\Qshpr\Model\QshprClient;
use Shubo\Qshpr\Model\Rates\CarDeliveryRule;
use Shubo\Qshpr\Model\Rates\Offer;
use Shubo\Qshpr\Model\Ui\ConfigProvider;
use Shubo\Qshpr\Model\WeightConverter;

/**
 * Books the courier for a new shipment of a Quickshipper order.
 *
 * Runs before Magento saves the shipment. It asks Quickshipper for a fresh
 * price for the same trip and the shipment's weight (a checkout quote is not
 * a reservation), books the courier the customer chose, and puts the
 * Quickshipper order number on the shipment as its tracking number.
 *
 * Quickshipper takes the delivery price when the order is created. If the
 * merchant's Quickshipper dashboard is set to assign the courier "after
 * confirmation", the new order is a Draft, which is confirmed at once; a
 * Draft can be neither cancelled nor deleted, so it is recorded before the
 * confirm is tried.
 *
 * A booking whose shipment was never saved (the save failed, or the confirm
 * was refused) is reused by the next shipment of the order instead of
 * booking and paying again — once Quickshipper confirms it is still active.
 * Bookings for one order are serialised by DeliveryLock, held from the
 * booking until the shipment's save commits.
 *
 * Every problem before the courier is booked and confirmed is a
 * DispatchException and the shipment is not saved. After that the courier is
 * on its way, so nothing else may stop the shipment: follow-up failures
 * (reading the order back, writing the first status) are logged and the
 * booking stands. The one exception is marking a confirmed Draft as
 * dispatched: without it the saved delivery would never be followed, so the
 * shipment stops and the next attempt reuses the confirmed booking.
 */
class Dispatcher
{
    /**
     * Shipment data key carrying the delivery row id until the shipment has
     * an id of its own (see the LinkShipment observer).
     */
    public const string SHIPMENT_DELIVERY_ID = 'shubo_qshpr_delivery_id';

    /**
     * Shipment data key: id of the order whose DeliveryLock this shipment
     * holds until its save commits (see ReleaseDeliveryLock).
     */
    public const string SHIPMENT_LOCKED_ORDER = 'shubo_qshpr_locked_order';

    /**
     * Shipment data key: the code creating the shipment books the courier
     * itself (automatic booking), so the Submit Shipment plugin must not.
     */
    public const string SHIPMENT_BOOKED_BY_CALLER = 'shubo_qshpr_booked_by_caller';

    /**
     * Quickshipper reads query flags as text: "true", not "1".
     */
    private const string QUERY_TRUE = 'true';

    /**
     * @param QshprClient $client
     * @param DeliveryLog $deliveryLog
     * @param OrderPayloadBuilder $payloadBuilder
     * @param Geocoder $geocoder
     * @param WeightConverter $weightConverter
     * @param Config $config
     * @param TrackFactory $trackFactory
     * @param DeliveryLock $lock
     * @param ShippedQuantities $shippedQuantities
     * @param CashOnDelivery $cashOnDelivery
     * @param CarDeliveryRule $carDeliveryRule
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly QshprClient $client,
        private readonly DeliveryLog $deliveryLog,
        private readonly OrderPayloadBuilder $payloadBuilder,
        private readonly Geocoder $geocoder,
        private readonly WeightConverter $weightConverter,
        private readonly Config $config,
        private readonly TrackFactory $trackFactory,
        private readonly DeliveryLock $lock,
        private readonly ShippedQuantities $shippedQuantities,
        private readonly CashOnDelivery $cashOnDelivery,
        private readonly CarDeliveryRule $carDeliveryRule,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Book the courier for a shipment; null when the order is not shipped with Quickshipper.
     *
     * @param Shipment $shipment Registered, not yet saved.
     * @return Booking|null
     * @throws DispatchException When the courier cannot be booked; the shipment must not be saved.
     */
    public function dispatch(Shipment $shipment): ?Booking
    {
        $order = $shipment->getOrder();
        $chosen = ChosenCourier::fromOrder($order);

        if ($chosen === null) {
            return null;
        }

        if ($order->getShippingAddress() === null) {
            throw new DispatchException(__('The order has no shipping address for the courier.'));
        }

        $orderId = (int)$order->getId();

        if (!$this->lock->acquire($orderId)) {
            throw new DispatchException(
                __(
                    'Another shipment of this order is booking its courier right now. '
                    . 'Reload the order and check its shipments before submitting again.'
                ),
                retryable: true
            );
        }

        try {
            // A Submit Shipment that waited for the lock holds the order as it
            // was loaded before the other shipment committed: Magento let it
            // ship the same items again, so it would book a second courier.
            if ($this->shippedQuantities->changedSinceLoad($shipment)) {
                throw new DispatchException(__(
                    'This order was shipped while this shipment was waiting (for example by automatic '
                    . 'booking). Nothing was booked; reload the order and check its shipments.'
                ));
            }

            $booking = $this->book($shipment, $order, $chosen);
        } catch (\Throwable $e) {
            $this->lock->release($orderId);

            throw $e;
        }

        // Kept until the shipment's save commits, so a second Submit Shipment
        // waits, then finds the order shipped instead of taking this booking
        // for one whose save failed.
        $shipment->setData(self::SHIPMENT_LOCKED_ORDER, $orderId);

        return $booking;
    }

    /**
     * Reuse the order's booking that no shipment carries yet, else book a new courier.
     *
     * @param Shipment $shipment
     * @param Order $order
     * @param ChosenCourier $chosen
     * @return Booking
     * @throws DispatchException
     */
    private function book(Shipment $shipment, Order $order, ChosenCourier $chosen): Booking
    {
        $unlinked = $this->deliveryLog->findUnlinkedBooking((int)$order->getId());
        $booking = $unlinked === null ? null : $this->resume($order, $unlinked);

        if ($booking !== null) {
            $this->attach($shipment, $order, $booking, (int)$unlinked['entity_id']);

            $this->logger->info(sprintf(
                'dispatch: order %s reused the unshipped booking %s %s (Quickshipper id %d); nothing new booked',
                $order->getIncrementId(),
                $booking->providerName,
                $booking->qshprOrderNo,
                $booking->qshprOrderId
            ));

            return $booking;
        }

        return $this->bookNew($shipment, $order, $chosen);
    }

    /**
     * An earlier booking of the order that no shipment carries: still active
     * at Quickshipper → confirmed if it is a Draft and returned for reuse;
     * over (cancelled, failed, unknown to Quickshipper) → marked abandoned, null.
     *
     * @param Order $order
     * @param array<string, mixed> $row The unlinked delivery row.
     * @return Booking|null
     * @throws DispatchException When Quickshipper cannot say, or the Draft is not confirmed.
     */
    private function resume(Order $order, array $row): ?Booking
    {
        $rowId = (int)$row['entity_id'];
        $qshprOrderId = (int)$row['qshpr_order_id'];
        $orderNo = (string)($row['qshpr_order_no'] ?? $qshprOrderId);

        try {
            $details = (array)($this->client->getOrder($qshprOrderId, (int)$order->getStoreId())['order'] ?? []);
        } catch (QshprApiException $e) {
            // Quickshipper answers 404 for an order it does not know.
            if ($e->getApiErrorCode() !== HttpResponse::STATUS_CODE_404) {
                throw new DispatchException(
                    __(
                        'This order has a Quickshipper booking (%1) that no shipment carries yet, and '
                        . 'Quickshipper did not say whether it is still active: %2 The shipment was not saved; '
                        . 'try again in a moment.',
                        $orderNo,
                        $e->getMessage()
                    ),
                    $e,
                    retryable: $e->getApiErrorCode() === null
                );
            }

            // Unknown to this Quickshipper account, e.g. booked before a
            // switch between the test and live environments: over, like a cancelled one.
            $details = ['status' => OrderStatus::Cancelled->value, 'notFound' => true];
        }

        $status = OrderStatus::fromAnswer($details['status'] ?? null);
        $statusName = match (true) {
            isset($details['notFound']) => 'not found',
            is_scalar($details['status'] ?? null) => (string)$details['status'],
            default => 'unknown',
        };

        if ($status !== null && $status->isEndedWithoutDelivery()) {
            $this->deliveryLog->markAbandoned($rowId);
            $this->logger->info(sprintf(
                'dispatch: order %s: unshipped booking %s (Quickshipper id %d) is %s at Quickshipper; booking anew',
                $order->getIncrementId(),
                $orderNo,
                $qshprOrderId,
                $statusName
            ));

            return null;
        }

        if ($status === OrderStatus::Draft
            || ($status === null && $row['state'] === DeliveryLog::STATE_DRAFTED)
        ) {
            $this->confirm($order, $qshprOrderId, $orderNo, $rowId);
        } elseif ($row['state'] === DeliveryLog::STATE_DRAFTED) {
            // Confirmed outside Magento, e.g. in the Quickshipper dashboard.
            $this->deliveryLog->markConfirmed($rowId);
        }

        return new Booking(
            (int)$row['provider_id'],
            (string)$row['provider_name'],
            $qshprOrderId,
            $orderNo,
            is_string($row['tracking_url']) && $row['tracking_url'] !== '' ? $row['tracking_url'] : null,
            (int)$row['charged_amount'],
            is_numeric($row['service_fee']) ? (int)$row['service_fee'] : null,
            (int)$row['paid_amount'],
            $this->deliveryLog->hasShippedDelivery((int)$order->getId()),
            isReused: true,
        );
    }

    /**
     * Book a new courier for a saved shipment whose delivery was cancelled or
     * failed ("Book courier again"). The new delivery is linked to the
     * shipment before the order's lock is released, so a second request finds
     * it and books nothing. The caller saves the new track and order comment,
     * since no shipment save follows.
     *
     * Replacing a cancelled delivery, whose price Quickshipper returned, is
     * still the delivery the customer paid for; replacing a failed one is an
     * extra delivery.
     *
     * @param Shipment $shipment Saved.
     * @return Booking
     * @throws DispatchException
     */
    public function rebook(Shipment $shipment): Booking
    {
        $order = $shipment->getOrder();
        $chosen = ChosenCourier::fromOrder($order);
        $shipmentId = (int)$shipment->getId();

        if ($chosen === null) {
            throw new DispatchException(__('This order is not shipped with Quickshipper.'));
        }

        if ($order->getShippingAddress() === null) {
            throw new DispatchException(__('The order has no shipping address for the courier.'));
        }

        $orderId = (int)$order->getId();

        if (!$this->lock->acquire($orderId)) {
            throw new DispatchException(
                __('Another booking of this order is running right now. Wait a moment and try again.'),
                retryable: true
            );
        }

        try {
            // Read under the lock: a second click waits above, then finds the
            // delivery already replaced.
            $replaced = $this->deliveryLog->findLatestForShipment($shipmentId);

            if ($replaced === null
                || !in_array($replaced['state'], [DeliveryLog::STATE_CANCELLED, DeliveryLog::STATE_FAILED], true)
            ) {
                throw new DispatchException(__(
                    'This shipment has no cancelled or failed Quickshipper delivery to replace.'
                ));
            }

            $booking = $this->bookNew($shipment, $order, $chosen, $replaced);
            $rowId = (int)$shipment->getData(self::SHIPMENT_DELIVERY_ID);

            if ($rowId > 0) {
                $this->deliveryLog->linkShipment($rowId, $shipmentId);
            }

            return $booking;
        } finally {
            $this->lock->release($orderId);
        }
    }

    /**
     * Book a new courier: fresh price, create, record, confirm a Draft.
     *
     * @param Shipment $shipment
     * @param Order $order
     * @param ChosenCourier $chosen
     * @param array<string, mixed>|null $replaced The cancelled or failed delivery this one replaces.
     * @return Booking
     * @throws DispatchException
     */
    private function bookNew(Shipment $shipment, Order $order, ChosenCourier $chosen, ?array $replaced = null): Booking
    {
        if ($this->cashOnDelivery->isCashPayment((string)$order->getPayment()?->getMethod())) {
            // Checkout hides cash for Quickshipper couriers; this catches orders
            // placed before that, or with the payment changed afterwards.
            throw new DispatchException(__(
                'The customer pays in cash on delivery, but Quickshipper has no cash-on-delivery service: the '
                . 'courier would not collect the money. The shipment was not saved; ship this order another way.'
            ));
        }

        $storeId = (int)$order->getStoreId();
        $orderId = (int)$order->getId();
        $address = $order->getShippingAddress();
        $quoted = $replaced === null ? $this->deliveryLog->findQuoted($orderId) : null;
        // No quoted row: a replacement for a cancelled or failed delivery, an
        // extra delivery (a partial shipment after a shipped one), a booking
        // replacing an abandoned one, or an order placed before deliveries
        // were recorded.
        $isExtraDelivery = $replaced !== null
            ? $replaced['state'] === DeliveryLog::STATE_FAILED || (int)$replaced['paid_amount'] === 0
            : $quoted === null && $this->deliveryLog->hasShippedDelivery($orderId);
        $abandoned = $quoted === null && $replaced === null && !$isExtraDelivery
            ? $this->deliveryLog->findLatest($orderId, [DeliveryLog::STATE_ABANDONED])
            : null;
        $paidAmount = match (true) {
            $quoted !== null => (int)$quoted['paid_amount'],
            $isExtraDelivery => 0,
            $replaced !== null => (int)$replaced['paid_amount'],
            $abandoned !== null => (int)$abandoned['paid_amount'],
            default => Money::toTetri((float)$order->getBaseShippingAmount()),
        };
        [$latitude, $longitude] = $this->dropOffPoint(
            $order,
            $replaced ?? $quoted ?? $this->deliveryLog->findLatest($orderId)
        );
        $weightKg = $this->shipmentWeightKg($shipment, $storeId);
        $carDelivery = $this->carDeliveryRule->forShipment($shipment, $storeId, $weightKg);
        [$fee, $price] = $this->freshPrice($order, $address, $chosen, $latitude, $longitude, $weightKg, $carDelivery);
        $payload = $this->payloadBuilder->build(
            $address,
            $latitude,
            $longitude,
            $chosen->providerId,
            (string)$price['id'],
            is_numeric($price['deliverySpeedId'] ?? null) ? (int)$price['deliverySpeedId'] : null,
            (string)$order->getIncrementId(),
            (float)$order->getBaseSubtotal(),
            $weightKg,
            $storeId,
            carDelivery: $carDelivery,
        );

        try {
            $created = $this->client->createOrder($payload, $storeId);
        } catch (QshprApiException $e) {
            // Never retried automatically: without an answer (a timeout) the
            // order may exist at Quickshipper all the same, and a second
            // create would book and charge a second courier.
            throw new DispatchException(
                $e->getApiErrorCode() === null
                    ? __(
                        'Quickshipper did not answer the booking: %1 The shipment was not saved. Check the '
                        . 'Quickshipper dashboard for an order from this attempt before booking again.',
                        $e->getMessage()
                    )
                    : __(
                        'Quickshipper did not book the courier: %1 The shipment was not saved. '
                        . 'Quickshipper may list a cancelled order for this attempt.',
                        $e->getMessage()
                    ),
                $e
            );
        }

        $qshprOrderId = is_numeric($created['orderId'] ?? null) ? (int)$created['orderId'] : 0;

        if ($qshprOrderId <= 0) {
            // A refused create was seen to leave a cancelled order without returning its id.
            throw new DispatchException(__(
                'Quickshipper answered without an order number; the shipment was not saved. Check the '
                . 'Quickshipper dashboard for an order from this attempt before booking again.'
            ));
        }

        // Quickshipper has accepted and taken the price: record the booking
        // before anything else can fail, so a retry finds and reuses it.
        $booking = $this->booking($qshprOrderId, $created, $fee, $price, $paidAmount, $isExtraDelivery, $storeId);
        $isDraft = OrderStatus::fromAnswer($created['orderStatus'] ?? null) === OrderStatus::Draft;
        $rowId = $this->record(
            $order,
            $quoted,
            $chosen,
            $latitude,
            $longitude,
            $booking,
            $isDraft ? DeliveryLog::STATE_DRAFTED : DeliveryLog::STATE_DISPATCHED
        );

        if ($isDraft) {
            $this->confirm($order, $qshprOrderId, $booking->qshprOrderNo, $rowId);
        } else {
            $this->recordFirstStatus($rowId, $created['orderStatus'] ?? null);
        }

        $this->attach($shipment, $order, $booking, $rowId);

        $this->logger->info(sprintf(
            'dispatch: order %s booked %s %s (Quickshipper id %d%s), charged %s GEL, customer paid %s GEL%s',
            $order->getIncrementId(),
            $booking->providerName,
            $booking->qshprOrderNo,
            $booking->qshprOrderId,
            $isDraft ? ', Draft confirmed' : '',
            Money::toGel($booking->chargedAmount),
            Money::toGel($booking->paidAmount),
            $booking->isExtraDelivery ? ' (extra delivery)' : ''
        ));

        return $booking;
    }

    /**
     * Send a Draft on to the courier company.
     *
     * A refusal stops the shipment and keeps the Draft recorded: the next
     * Submit Shipment confirms the same Draft instead of booking again.
     *
     * @param Order $order
     * @param int $qshprOrderId
     * @param string $orderNo
     * @param int|null $rowId The booking's delivery row, null if it could not be written.
     * @return void
     * @throws DispatchException
     */
    private function confirm(Order $order, int $qshprOrderId, string $orderNo, ?int $rowId): void
    {
        try {
            $answer = $this->client->confirmOrder($qshprOrderId, (int)$order->getStoreId());
        } catch (QshprApiException $e) {
            $this->logger->warning(sprintf(
                'dispatch: order %s: Quickshipper Draft %s (id %d) not confirmed: %s',
                $order->getIncrementId(),
                $orderNo,
                $qshprOrderId,
                $e->getMessage()
            ));

            throw new DispatchException(
                __(
                    'Quickshipper did not confirm booking %1: %2 The shipment was not saved. The booking is '
                    . 'kept: submit the shipment again to retry the confirmation, without a new booking or charge.',
                    $orderNo,
                    $e->getMessage()
                ),
                $e,
                retryable: $e->getApiErrorCode() === null
            );
        }

        if (OrderStatus::fromAnswer($answer['orderStatus'] ?? null)?->isEndedWithoutDelivery()) {
            if ($rowId !== null) {
                $this->deliveryLog->markAbandoned($rowId);
            }

            throw new DispatchException(__(
                'Quickshipper cancelled booking %1 when it was confirmed: the courier company did not accept it. '
                . 'The shipment was not saved; submit it again to book anew, or ship this order another way.',
                $orderNo
            ));
        }

        if ($rowId === null) {
            return;
        }

        // The courier is confirmed but the row still says "drafted". A shipment
        // saved now would carry a delivery that status checks, Cancel and
        // cancel-on-refund all skip, so the shipment is stopped instead: the
        // next Submit Shipment finds the Draft confirmed and reuses it.
        try {
            $this->deliveryLog->markConfirmed($rowId);
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf(
                'dispatch: order %s: Quickshipper confirmed %s (id %d) but the delivery record was not updated: %s',
                $order->getIncrementId(),
                $orderNo,
                $qshprOrderId,
                $e->getMessage()
            ), ['exception' => $e]);

            throw new DispatchException(
                __(
                    'Quickshipper confirmed booking %1, but Magento could not record it. The shipment was not '
                    . 'saved. Submit it again: the same booking is used, nothing new is booked or charged.',
                    $orderNo
                ),
                $e instanceof \Exception ? $e : null,
                retryable: true
            );
        }

        $this->recordFirstStatus($rowId, $answer['orderStatus'] ?? null);
    }

    /**
     * Keep the status Quickshipper returned with the booking or the confirm.
     *
     * Quickshipper sends its status webhook while that very request is still
     * running, so the webhook finds the order locked and is refused; it was
     * not seen to be sent again. Without this the delivery would show no
     * status until the next status check. A failed write is only logged: the
     * courier is booked, and the next status check records it.
     *
     * @param int|null $rowId
     * @param mixed $status The answer's orderStatus.
     * @return void
     */
    private function recordFirstStatus(?int $rowId, mixed $status): void
    {
        if ($rowId === null || !is_string($status) || $status === ''
            || OrderStatus::fromAnswer($status)?->isEndedWithoutDelivery()
        ) {
            return;
        }

        try {
            $this->deliveryLog->recordStatus($rowId, $status, null, null);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'dispatch: delivery %d: first status %s not recorded: %s',
                $rowId,
                $status,
                $e->getMessage()
            ));
        }
    }

    /**
     * Put the booking on the shipment and the order: tracking number, order
     * comment, and the delivery row to link once the shipment is saved.
     *
     * @param Shipment $shipment
     * @param Order $order
     * @param Booking $booking
     * @param int|null $rowId
     * @return void
     */
    private function attach(Shipment $shipment, Order $order, Booking $booking, ?int $rowId): void
    {
        $this->addTrack($shipment, $booking);
        $this->addOrderComment($order, $booking);

        if ($rowId !== null) {
            $shipment->setData(self::SHIPMENT_DELIVERY_ID, $rowId);
        }
    }

    /**
     * Drop-off coordinates: those the customer was quoted for, else the
     * shipping address geocoded now (orders placed before deliveries were recorded).
     *
     * @param Order $order
     * @param array<string, mixed>|null $known The order's delivery row, if any.
     * @return array{float, float}
     * @throws DispatchException
     */
    private function dropOffPoint(Order $order, ?array $known): array
    {
        if ($known !== null && is_numeric($known['dropoff_latitude']) && is_numeric($known['dropoff_longitude'])) {
            return [(float)$known['dropoff_latitude'], (float)$known['dropoff_longitude']];
        }

        $address = $order->getShippingAddress();
        $location = $address === null ? null : $this->geocoder->locate(
            new Address(
                $address->getStreetLine(1),
                (string)$address->getCity(),
                (string)$address->getCountryId()
            ),
            (int)$order->getStoreId()
        );

        if ($location === null) {
            throw new DispatchException(
                __('The shipping address could not be located precisely enough to send a courier.')
            );
        }

        return [$location->latitude, $location->longitude];
    }

    /**
     * Weight of the shipped items in kg (0 when products have no weight).
     *
     * @param Shipment $shipment
     * @param int $storeId
     * @return float
     */
    private function shipmentWeightKg(Shipment $shipment, int $storeId): float
    {
        $weight = 0.0;

        foreach ($shipment->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();

            // Same rule Magento uses to count shipped quantities: a child
            // shipped together with its parent is not a parcel of its own.
            if ($orderItem === null || $orderItem->isDummy(true)) {
                continue;
            }

            $weight += (float)$orderItem->getWeight() * (float)$item->getQty();
        }

        return $this->weightConverter->toKg($weight, $storeId);
    }

    /**
     * The chosen courier's current price for this trip: the same price id if
     * still offered, else the courier's only price (e.g. another weight
     * bracket for a partial shipment).
     *
     * @param Order $order
     * @param OrderAddress $address The shipping address (callers check that the order has one).
     * @param ChosenCourier $chosen
     * @param float $latitude
     * @param float $longitude
     * @param float $weightKg
     * @param bool $carDelivery Ask for couriers with a car, as the booking will.
     * @return array{array<string, mixed>, array<string, mixed>} The courier's fees entry and the price.
     * @throws DispatchException
     */
    private function freshPrice(
        Order $order,
        OrderAddress $address,
        ChosenCourier $chosen,
        float $latitude,
        float $longitude,
        float $weightKg,
        bool $carDelivery,
    ): array {
        $storeId = (int)$order->getStoreId();

        try {
            $pickup = $this->config->getPickupPoint($storeId);
        } catch (PickupNotConfiguredException $e) {
            throw new DispatchException(new Phrase($e->getRawMessage(), $e->getParameters()), $e);
        }

        $query = [
            'FromStreetName' => $pickup->street,
            'FromCityName' => $pickup->city,
            'FromLatitude' => $pickup->latitude,
            'FromLongitude' => $pickup->longitude,
            'ToStreetName' => $address->getStreetLine(1),
            'ToCityName' => (string)$address->getCity(),
            'ToLatitude' => $latitude,
            'ToLongitude' => $longitude,
            'CartAmount' => round((float)$order->getBaseSubtotal(), 2),
        ];

        if ($weightKg > 0) {
            $query['CartWeight'] = $weightKg;
        }

        if ($carDelivery) {
            $query['CarDelivery'] = self::QUERY_TRUE;
        }

        try {
            $response = $this->client->getFees($query, $storeId);
        } catch (QshprApiException $e) {
            throw new DispatchException(
                __('Quickshipper did not answer the price check: %1 Try again in a moment.', $e->getMessage()),
                $e,
                retryable: $e->getApiErrorCode() === null
            );
        }

        foreach ((array)($response['fees'] ?? []) as $fee) {
            if (!is_array($fee)
                || (int)($fee['providerId'] ?? 0) !== $chosen->providerId
                || ($fee['isActive'] ?? true) === false
            ) {
                continue;
            }

            $prices = array_values(array_filter(
                (array)($fee['prices'] ?? []),
                static fn ($price): bool => is_array($price)
                    && ($price['currencyName'] ?? null) === Money::CURRENCY
                    && is_numeric($price['amount'] ?? null)
                    && (float)$price['amount'] > 0
                    && is_scalar($price['id'] ?? null)
                    && (string)$price['id'] !== ''
            ));

            foreach ($prices as $price) {
                if (Offer::priceKey((string)$price['id']) === $chosen->priceKey) {
                    return [$fee, $price];
                }
            }

            if (count($prices) === 1) {
                return [$fee, $prices[0]];
            }
        }

        throw new DispatchException(__(
            'Quickshipper no longer offers the courier the customer chose for this delivery. '
            . 'The shipment was not saved; ship this order another way.'
        ));
    }

    /**
     * The booking as Quickshipper recorded it. Reads the order back for its
     * number (SQ…) and price; if that read fails, the create answer is used.
     *
     * @param int $qshprOrderId
     * @param array<string, mixed> $created POST /v1/Order answer.
     * @param array<string, mixed> $fee
     * @param array<string, mixed> $price
     * @param int $paidAmount Shipping the customer paid for this delivery, in tetri.
     * @param bool $isExtraDelivery
     * @param int $storeId
     * @return Booking
     */
    private function booking(
        int $qshprOrderId,
        array $created,
        array $fee,
        array $price,
        int $paidAmount,
        bool $isExtraDelivery,
        int $storeId,
    ): Booking {
        $details = [];

        try {
            $details = (array)($this->client->getOrder($qshprOrderId, $storeId)['order'] ?? []);
        } catch (QshprApiException $e) {
            $this->logger->warning(sprintf(
                'dispatch: Quickshipper order %d was booked but could not be read back: %s',
                $qshprOrderId,
                $e->getMessage()
            ));
        }

        $orderNo = is_scalar($details['orderNo'] ?? null) && (string)$details['orderNo'] !== ''
            ? (string)$details['orderNo']
            : (string)$qshprOrderId;
        $trackingUrl = $details['trackingUrl'] ?? $created['trackingUrl'] ?? null;
        $charged = $details['orderPrice'] ?? $created['deliveryFee'] ?? $price['amount'];
        $serviceFee = $details['serviceFee'] ?? $fee['serviceFee'] ?? null;

        return new Booking(
            (int)$fee['providerId'],
            trim((string)($fee['providerName'] ?? '')),
            $qshprOrderId,
            $orderNo,
            is_string($trackingUrl) && $trackingUrl !== '' ? $trackingUrl : null,
            is_numeric($charged) ? Money::toTetri($charged) : 0,
            is_numeric($serviceFee) ? Money::toTetri($serviceFee) : null,
            $paidAmount,
            $isExtraDelivery,
        );
    }

    /**
     * Quickshipper's order number becomes the shipment's tracking number.
     *
     * @param Shipment $shipment
     * @param Booking $booking
     * @return void
     */
    private function addTrack(Shipment $shipment, Booking $booking): void
    {
        $track = $this->trackFactory->create();
        $track->setCarrierCode(ConfigProvider::CODE);
        $track->setTitle(trim('Quickshipper ' . $booking->providerName));
        $track->setTrackNumber($booking->qshprOrderNo);
        $shipment->addTrack($track);
    }

    /**
     * An order history note of what was booked and what it cost.
     *
     * @param Order $order
     * @param Booking $booking
     * @return void
     */
    private function addOrderComment(Order $order, Booking $booking): void
    {
        $comment = match (true) {
            $booking->isReused => __(
                'Quickshipper booking from an earlier shipment attempt that was not saved is used for this '
                . 'shipment: %1, order %2, %3 GEL. Nothing new was booked.',
                $booking->providerName,
                $booking->qshprOrderNo,
                Money::toGel($booking->chargedAmount)
            ),
            $booking->isExtraDelivery => __(
                'Extra Quickshipper delivery for this order: %1, order %2, %3 GEL. '
                . 'The customer paid for one delivery.',
                $booking->providerName,
                $booking->qshprOrderNo,
                Money::toGel($booking->chargedAmount)
            ),
            default => __(
                'Courier booked with Quickshipper: %1, order %2, %3 GEL (the customer paid %4 GEL).',
                $booking->providerName,
                $booking->qshprOrderNo,
                Money::toGel($booking->chargedAmount),
                Money::toGel($booking->paidAmount)
            ),
        };

        $order->addCommentToStatusHistory($comment->render());
    }

    /**
     * Write the booking to the delivery record. A failure here is logged,
     * not thrown: the courier is already booked.
     *
     * @param Order $order
     * @param array<string, mixed>|null $quoted
     * @param ChosenCourier $chosen
     * @param float $latitude
     * @param float $longitude
     * @param Booking $booking
     * @param string $state DeliveryLog::STATE_DRAFTED or STATE_DISPATCHED.
     * @return int|null Row id, null when the write failed.
     */
    private function record(
        Order $order,
        ?array $quoted,
        ChosenCourier $chosen,
        float $latitude,
        float $longitude,
        Booking $booking,
        string $state,
    ): ?int {
        try {
            if ($quoted !== null && $this->deliveryLog->markBooked((int)$quoted['entity_id'], $booking, $state)) {
                return (int)$quoted['entity_id'];
            }

            return $this->deliveryLog->recordBooking(
                (int)$order->getId(),
                (string)$order->getIncrementId(),
                (int)$order->getStoreId(),
                $chosen->priceKey,
                $latitude,
                $longitude,
                $booking->paidAmount,
                $booking,
                $state
            );
        } catch (\Throwable $e) {
            $this->logger->critical(sprintf(
                'dispatch: order %s booked Quickshipper order %d (%s) but the delivery record failed: %s',
                $order->getIncrementId(),
                $booking->qshprOrderId,
                $booking->qshprOrderNo,
                $e->getMessage()
            ), ['exception' => $e]);

            return null;
        }
    }
}
