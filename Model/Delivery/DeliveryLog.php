<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Delivery;

use Magento\Framework\App\ResourceConnection;

/**
 * The module's record of Quickshipper deliveries (shubo_qshpr_delivery).
 *
 * A row starts as "quoted" when an order is placed with a Quickshipper
 * courier. Booking moves it to "drafted" (Quickshipper holds a Draft that
 * still needs confirming) or straight to "dispatched" (sent to the courier
 * company); confirming moves "drafted" to "dispatched". A booking whose
 * shipment was never saved and that Quickshipper no longer carries out
 * becomes "abandoned" — the row stays as the merchant's record of it. A
 * shipped delivery ends "delivered", "cancelled" or "failed", following
 * Quickshipper's status; qshpr_status keeps the status last read.
 *
 * States only move forward; the WHERE clause of each update enforces that,
 * so a repeated or racing update cannot move the same row twice.
 */
class DeliveryLog
{
    public const string TABLE = 'shubo_qshpr_delivery';

    /** Order placed; the courier is not booked yet. */
    public const string STATE_QUOTED = 'quoted';

    /** Quickshipper holds the booking as a Draft that is not confirmed yet. */
    public const string STATE_DRAFTED = 'drafted';

    /** Courier booked at Quickshipper. */
    public const string STATE_DISPATCHED = 'dispatched';

    /** Booked but never shipped in Magento, and no longer carried out by Quickshipper. */
    public const string STATE_ABANDONED = 'abandoned';

    /** Shipped and delivered (final). */
    public const string STATE_DELIVERED = 'delivered';

    /** Shipped, then cancelled at Quickshipper (final). */
    public const string STATE_CANCELLED = 'cancelled';

    /** Shipped, then the pickup or delivery failed (final). */
    public const string STATE_FAILED = 'failed';

    /** States of a booking Quickshipper may still carry out. */
    public const array BOOKED_STATES = [self::STATE_DRAFTED, self::STATE_DISPATCHED];

    /**
     * States of a delivery that used up the delivery the customer paid for:
     * under way, delivered, or failed after a courier tried. A cancelled one
     * returned its price.
     */
    private const array SHIPPED_STATES = [self::STATE_DISPATCHED, self::STATE_DELIVERED, self::STATE_FAILED];

    /** Length of the last_error column. */
    private const int LAST_ERROR_LENGTH = 255;

    /** Length of the courier_name column. */
    private const int COURIER_NAME_LENGTH = 128;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Record what the customer chose at checkout.
     *
     * @param int $orderId
     * @param string $incrementId
     * @param int $storeId
     * @param int $providerId
     * @param string $priceKey
     * @param float|null $latitude Drop-off point the customer was quoted for.
     * @param float|null $longitude
     * @param int $paidAmount Shipping the customer paid, in tetri.
     * @return int Row id.
     */
    public function recordQuote(
        int $orderId,
        string $incrementId,
        int $storeId,
        int $providerId,
        string $priceKey,
        ?float $latitude,
        ?float $longitude,
        int $paidAmount,
    ): int {
        $connection = $this->resource->getConnection();
        $connection->insert($this->table(), [
            'order_id' => $orderId,
            'increment_id' => $incrementId,
            'store_id' => $storeId,
            'provider_id' => $providerId,
            'price_key' => $priceKey,
            'dropoff_latitude' => $latitude,
            'dropoff_longitude' => $longitude,
            'paid_amount' => $paidAmount,
            'state' => self::STATE_QUOTED,
        ]);

        return $this->insertedId();
    }

    /**
     * One row by id, or null.
     *
     * @param int $entityId
     * @return array<string, mixed>|null
     */
    public function find(int $entityId): ?array
    {
        return $this->fetchOne(['entity_id = ?' => $entityId]);
    }

    /**
     * The delivery booked as Quickshipper order $qshprOrderId, or null.
     *
     * @param int $qshprOrderId
     * @return array<string, mixed>|null
     */
    public function findByQshprOrderId(int $qshprOrderId): ?array
    {
        return $this->fetchOne(['qshpr_order_id = ?' => $qshprOrderId]);
    }

    /**
     * The order's shipped deliveries still under way.
     *
     * @param int $orderId
     * @return array<int, array<string, mixed>>
     */
    public function findShippedUnderWay(int $orderId): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll($connection->select()
            ->from($this->table())
            ->where('order_id = ?', $orderId)
            ->where('state = ?', self::STATE_DISPATCHED)
            ->where('shipment_id IS NOT NULL')
            ->order('entity_id ASC'));
    }

    /**
     * The latest delivery linked to a shipment, in any state, or null.
     *
     * @param int $shipmentId
     * @return array<string, mixed>|null
     */
    public function findLatestForShipment(int $shipmentId): ?array
    {
        return $this->fetchOne(['shipment_id = ?' => $shipmentId]);
    }

    /**
     * Shipped deliveries still under way whose status has not been asked
     * for $minutes, never-asked first.
     *
     * @param int $minutes
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function findStatusDue(int $minutes, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table())
            ->where('state = ?', self::STATE_DISPATCHED)
            ->where('shipment_id IS NOT NULL')
            ->where('qshpr_order_id IS NOT NULL')
            ->where('alerted_at IS NULL')
            ->where(
                'status_checked_at IS NULL OR status_checked_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)',
                $minutes
            )
            ->order('status_checked_at ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    /**
     * Record the status Quickshipper reported for a shipped delivery. Only a
     * "dispatched" row changes, so a final state is never left again.
     *
     * @param int $entityId
     * @param string $status Quickshipper's status name.
     * @param string|null $courierName
     * @param string|null $finalState STATE_DELIVERED, STATE_CANCELLED, STATE_FAILED, or null while under way.
     * @return bool Whether the row changed.
     */
    public function recordStatus(int $entityId, string $status, ?string $courierName, ?string $finalState): bool
    {
        $columns = [
            'qshpr_status' => $status,
            'status_changed_at' => new \Zend_Db_Expr('NOW()'),
            'status_checked_at' => new \Zend_Db_Expr('NOW()'),
        ];

        if ($courierName !== null) {
            $columns['courier_name'] = mb_substr($courierName, 0, self::COURIER_NAME_LENGTH);
        }

        if ($finalState !== null) {
            $columns['state'] = $finalState;
        }

        return $this->resource->getConnection()->update(
            $this->table(),
            $columns,
            ['entity_id = ?' => $entityId, 'state = ?' => self::STATE_DISPATCHED]
        ) === 1;
    }

    /**
     * Note that Quickshipper was asked and nothing changed.
     *
     * @param int $entityId
     * @return void
     */
    public function markStatusChecked(int $entityId): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['status_checked_at' => new \Zend_Db_Expr('NOW()')],
            ['entity_id = ?' => $entityId]
        );
    }

    /**
     * The order's delivery still waiting for its courier, or null.
     *
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public function findQuoted(int $orderId): ?array
    {
        return $this->fetchOne(['order_id = ?' => $orderId, 'state = ?' => self::STATE_QUOTED]);
    }

    /**
     * The order's most recent delivery row, in any of $states (any state when empty), or null.
     *
     * @param int $orderId
     * @param string[] $states
     * @return array<string, mixed>|null
     */
    public function findLatest(int $orderId, array $states = []): ?array
    {
        $where = ['order_id = ?' => $orderId];

        if ($states !== []) {
            $where['state IN (?)'] = $states;
        }

        return $this->fetchOne($where);
    }

    /**
     * The order's booking that no saved shipment carries yet, or null.
     *
     * Such a row is left when Magento's shipment save failed after
     * Quickshipper accepted, or when a Draft could not be confirmed. The
     * merchant's next Submit Shipment reuses it instead of booking again.
     *
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public function findUnlinkedBooking(int $orderId): ?array
    {
        return $this->fetchOne([
            'order_id = ?' => $orderId,
            'state IN (?)' => self::BOOKED_STATES,
            'shipment_id IS NULL' => null,
            'qshpr_order_id IS NOT NULL' => null,
        ]);
    }

    /**
     * Whether a saved shipment of the order already used up its paid delivery.
     *
     * Counts deliveries under way, delivered or failed (SHIPPED_STATES).
     *
     * @param int $orderId
     * @return bool
     */
    public function hasShippedDelivery(int $orderId): bool
    {
        return $this->fetchOne([
            'order_id = ?' => $orderId,
            'state IN (?)' => self::SHIPPED_STATES,
            'shipment_id IS NOT NULL' => null,
        ]) !== null;
    }

    /**
     * The delivery booked under a Quickshipper order number, or null.
     *
     * @param string $qshprOrderNo e.g. "SQ42609".
     * @return array<string, mixed>|null
     */
    public function findByOrderNo(string $qshprOrderNo): ?array
    {
        return $this->fetchOne(['qshpr_order_no = ?' => $qshprOrderNo]);
    }

    /**
     * Bookings without a shipment, untouched for $graceMinutes and not yet
     * reported to the merchant, oldest first.
     *
     * The grace period keeps a booking whose shipment is still being saved
     * out of reach. The age is compared in SQL, against the database clock
     * that also wrote updated_at.
     *
     * @param int $graceMinutes
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function findStaleUnlinked(int $graceMinutes, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table())
            ->where('shipment_id IS NULL')
            ->where('state IN (?)', self::BOOKED_STATES)
            ->where('qshpr_order_id IS NOT NULL')
            ->where('alerted_at IS NULL')
            ->where('updated_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)', $graceMinutes)
            ->order('entity_id ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    /**
     * Quoted rows whose automatic booking is due, oldest first.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function findDispatchDue(int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table())
            ->where('state = ?', self::STATE_QUOTED)
            ->where('dispatch_due_at <= NOW()')
            ->order('dispatch_due_at ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    /**
     * The order is paid: its quoted delivery is due for automatic booking.
     * Set once; a row that already had attempts is not made due again.
     *
     * @param int $orderId
     * @return bool Whether a row was marked.
     */
    public function markDispatchDue(int $orderId): bool
    {
        return $this->resource->getConnection()->update(
            $this->table(),
            ['dispatch_due_at' => new \Zend_Db_Expr('NOW()')],
            [
                'order_id = ?' => $orderId,
                'state = ?' => self::STATE_QUOTED,
                'dispatch_due_at IS NULL',
                'dispatch_attempts = 0',
            ]
        ) > 0;
    }

    /**
     * Note a failed automatic booking: due again after $retryInMinutes, or
     * no longer due when null.
     *
     * @param int $entityId
     * @param string $error
     * @param int|null $retryInMinutes
     * @return void
     */
    public function recordDispatchFailure(int $entityId, string $error, ?int $retryInMinutes): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            [
                'dispatch_attempts' => new \Zend_Db_Expr('dispatch_attempts + 1'),
                'last_error' => mb_substr($error, 0, self::LAST_ERROR_LENGTH),
                'dispatch_due_at' => $retryInMinutes === null
                    ? null
                    : new \Zend_Db_Expr(sprintf('NOW() + INTERVAL %d MINUTE', $retryInMinutes)),
            ],
            ['entity_id = ?' => $entityId]
        );
    }

    /**
     * Automatic booking is no longer due (the order cannot be shipped any
     * more, or the setting was switched off).
     *
     * @param int $entityId
     * @return void
     */
    public function clearDispatchDue(int $entityId): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['dispatch_due_at' => null],
            ['entity_id = ?' => $entityId]
        );
    }

    /**
     * Record a booking on the order's quoted row. Only a row still in "quoted" moves.
     *
     * @param int $entityId
     * @param Booking $booking
     * @param string $state STATE_DRAFTED or STATE_DISPATCHED.
     * @return bool Whether the row moved.
     */
    public function markBooked(int $entityId, Booking $booking, string $state): bool
    {
        return $this->resource->getConnection()->update(
            $this->table(),
            $this->bookingColumns($booking) + ['state' => $state],
            ['entity_id = ?' => $entityId, 'state = ?' => self::STATE_QUOTED]
        ) === 1;
    }

    /**
     * Record a booking that has no quoted row: an extra delivery (partial
     * shipment), a booking that replaces an abandoned one, or an order placed
     * before this record existed.
     *
     * @param int $orderId
     * @param string $incrementId
     * @param int $storeId
     * @param string $priceKey
     * @param float $latitude
     * @param float $longitude
     * @param int $paidAmount Shipping the customer paid for this delivery, in tetri.
     * @param Booking $booking
     * @param string $state STATE_DRAFTED or STATE_DISPATCHED.
     * @return int Row id.
     */
    public function recordBooking(
        int $orderId,
        string $incrementId,
        int $storeId,
        string $priceKey,
        float $latitude,
        float $longitude,
        int $paidAmount,
        Booking $booking,
        string $state,
    ): int {
        $connection = $this->resource->getConnection();
        $connection->insert($this->table(), [
            'order_id' => $orderId,
            'increment_id' => $incrementId,
            'store_id' => $storeId,
            'provider_id' => $booking->providerId,
            'price_key' => $priceKey,
            'dropoff_latitude' => $latitude,
            'dropoff_longitude' => $longitude,
            'paid_amount' => $paidAmount,
            'state' => $state,
        ] + $this->bookingColumns($booking));

        return $this->insertedId();
    }

    /**
     * A Draft was confirmed: "drafted" → "dispatched".
     *
     * @param int $entityId
     * @return bool Whether the row moved.
     */
    public function markConfirmed(int $entityId): bool
    {
        return $this->resource->getConnection()->update(
            $this->table(),
            ['state' => self::STATE_DISPATCHED],
            ['entity_id = ?' => $entityId, 'state = ?' => self::STATE_DRAFTED]
        ) === 1;
    }

    /**
     * A booking without a shipment is over at Quickshipper. Only an unlinked
     * "drafted" or "dispatched" row moves.
     *
     * @param int $entityId
     * @return bool Whether the row moved.
     */
    public function markAbandoned(int $entityId): bool
    {
        return $this->resource->getConnection()->update(
            $this->table(),
            ['state' => self::STATE_ABANDONED],
            ['entity_id = ?' => $entityId, 'state IN (?)' => self::BOOKED_STATES, 'shipment_id IS NULL']
        ) === 1;
    }

    /**
     * Note that the merchant was told about a booking that needs them, so it
     * is reported once.
     *
     * @param int $entityId
     * @return bool False when it had been reported already.
     */
    public function markAlerted(int $entityId): bool
    {
        return $this->resource->getConnection()->update(
            $this->table(),
            ['alerted_at' => new \Zend_Db_Expr('NOW()')],
            ['entity_id = ?' => $entityId, 'alerted_at IS NULL']
        ) === 1;
    }

    /**
     * Link a booked delivery to the shipment Magento saved for it.
     *
     * @param int $entityId
     * @param int $shipmentId
     * @return void
     */
    public function linkShipment(int $entityId, int $shipmentId): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['shipment_id' => $shipmentId],
            ['entity_id = ?' => $entityId, 'shipment_id IS NULL']
        );
    }

    /**
     * Columns a booking fills.
     *
     * @param Booking $booking
     * @return array<string, int|string|null>
     */
    private function bookingColumns(Booking $booking): array
    {
        return [
            'provider_name' => $booking->providerName,
            'charged_amount' => $booking->chargedAmount,
            'service_fee' => $booking->serviceFee,
            'qshpr_order_id' => $booking->qshprOrderId,
            'qshpr_order_no' => $booking->qshprOrderNo,
            'tracking_url' => $booking->trackingUrl,
        ];
    }

    /**
     * Newest row matching all conditions, or null. A condition without a
     * placeholder (e.g. "shipment_id IS NULL") takes a null value.
     *
     * @param array<string, int|string|string[]|null> $where
     * @return array<string, mixed>|null
     */
    private function fetchOne(array $where): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()->from($this->table())->order('entity_id DESC')->limit(1);

        foreach ($where as $condition => $value) {
            $select->where($condition, $value);
        }

        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }

    /**
     * Seconds from a timestamp this table stored until now, by the database's clock.
     *
     * @param string $timestamp As read from a timestamp column.
     * @return int
     */
    public function secondsSince(string $timestamp): int
    {
        return (int)$this->resource->getConnection()->fetchOne(
            'SELECT TIMESTAMPDIFF(SECOND, ?, NOW())',
            [$timestamp]
        );
    }

    /**
     * Id of the row this connection inserted last.
     *
     * AdapterInterface does not declare lastInsertId(); MySQL's
     * LAST_INSERT_ID() is per connection, so another request's insert cannot
     * interfere.
     *
     * @return int
     */
    private function insertedId(): int
    {
        return (int)$this->resource->getConnection()->fetchOne('SELECT LAST_INSERT_ID()');
    }

    /**
     * Full table name (with the installation's prefix).
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
