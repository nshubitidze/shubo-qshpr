<?php
// Checks for what happens after a shipment: status updates (Status\StatusUpdater,
// the webhook Controller\Webhook\Status, Cron\StatusSync, Status\WebhookRegistrar,
// the status in the carrier's tracking info) and cancelling (Dispatch\Canceller,
// Dispatch\Rebooker, Plugin\ShipmentViewButtons, Observer\CancelOnRefund).
//  1. pieces: OrderStatus labels and final states, webhook answer codes
//  2. on a real local Quickshipper order, inside a database transaction that is
//     rolled back at the end: a shipment is saved for it, a booked delivery is
//     linked to the shipment, then webhooks and the cron run over a STUB
//     Quickshipper client — key, body, unknown order, re-read, each status,
//     repeats, final states, alerts, busy order, polling, stale deliveries;
//     cancel before / after a courier, already cancelled or delivered, refused,
//     busy; book again after a cancelled or failed delivery; the shipment page
//     buttons; cancel on full refund
//  3. registering the webhook over a stub client (nothing stored)
//  4. log assertions on var/log/shubo_qshpr.log (this run's lines only)
//
// Nothing is sent to Quickshipper and nothing stays in the database. Section 2
// needs one order placed with Quickshipper that still has items to ship (the
// checkout browser test makes one); QSHPR_ORDER=<increment id> picks it.
//
// Run inside phpfpm from the Magento root: php <module-dir>/dev/checks/qshpr-status-check.php

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Shipping\Block\Adminhtml\View as ShipmentView;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Shipping\Model\CarrierFactory;
use Magento\Store\Model\StoreManagerInterface;
use Shubo\Qshpr\Controller\Adminhtml\Delivery\Cancel;
use Shubo\Qshpr\Controller\Adminhtml\Delivery\Rebook;
use Shubo\Qshpr\Controller\Webhook\Status as StatusWebhook;
use Shubo\Qshpr\Cron\StatusSync;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Delivery\Booking;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Exception\DispatchException;
use Shubo\Qshpr\Model\Dispatch\CancelOutcome;
use Shubo\Qshpr\Model\Dispatch\Canceller;
use Shubo\Qshpr\Model\Dispatch\DeliveryLock;
use Shubo\Qshpr\Model\Dispatch\Rebooker;
use Shubo\Qshpr\Observer\CancelOnRefund;
use Shubo\Qshpr\Plugin\ShipmentViewButtons;
use Shubo\Qshpr\Model\OrderStatus;
use Shubo\Qshpr\Model\QshprClient;
use Shubo\Qshpr\Model\Status\StatusUpdater;
use Shubo\Qshpr\Model\Status\UpdateResult;
use Shubo\Qshpr\Model\Status\WebhookRegistrar;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
// The webhook arrives in the frontend area, where no booking plugin runs.
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

require __DIR__ . '/_stubs.php';

$logFile = BP . '/var/log/shubo_qshpr.log';
$logOffset = is_file($logFile) ? (int)filesize($logFile) : 0;

const P = 'carriers/shuboqshpr/';
const KEY = 'check-key-0123456789';

/** Make the object manager hand out $instance for $type (dev checks only). */
function share($om, string $type, object $instance): void
{
    (function () use ($type, $instance): void {
        $this->_sharedInstances[ltrim($type, '\\')] = $instance;
    })->call($om);
}

$encryptor = $om->get(EncryptorInterface::class);
$realScope = $om->get(ScopeConfigInterface::class);
const STORE = [
    P . 'pickup_latitude' => '41.6934',
    P . 'pickup_longitude' => '44.8015',
    P . 'cancel_on_refund' => '1',
    'shipping/origin/street_line1' => 'Freedom Square 1',
    'shipping/origin/city' => 'Tbilisi',
    'general/store_information/name' => 'Check Shop',
    'general/store_information/phone' => '322000000',
];
$config = $om->create(Config::class, [
    'scopeConfig' => overrideScope([P . 'webhook_key' => $encryptor->encrypt(KEY)] + STORE, $realScope),
]);

// --- 1. Pieces ---
check('status: labels for people', (string)OrderStatus::OnTheWay->label() === 'On the way'
    && (string)OrderStatus::ReadyForPickup->label() === 'Sent to the courier company');
check('webhook answers: busy → 503 (Quickshipper retries), the rest 200', UpdateResult::Busy->httpStatus() === 503
    && UpdateResult::Updated->httpStatus() === 200 && UpdateResult::NotOurs->httpStatus() === 200);

// --- 2. Status updates over a stub client, rolled back ---
$resource = $om->get(ResourceConnection::class);
$connection = $resource->getConnection();
$table = $resource->getTableName(DeliveryLog::TABLE);
$wanted = (string)getenv('QSHPR_ORDER');
$select = $connection->select()->from(['o' => $resource->getTableName('sales_order')], ['entity_id'])
    ->join(['i' => $resource->getTableName('sales_order_item')], 'i.order_id = o.entity_id', [])
    ->where('o.shipping_method LIKE ?', 'shuboqshpr\_%')
    ->where('o.state IN (?)', [Order::STATE_NEW, Order::STATE_PROCESSING])
    ->where('i.parent_item_id IS NULL')
    ->where('i.qty_ordered > i.qty_shipped + i.qty_refunded + i.qty_canceled')
    ->group('o.entity_id')
    ->order('o.entity_id DESC')->limit(1);
if ($wanted !== '') {
    $select->where('o.increment_id = ?', $wanted);
}
$orderId = (int)$connection->fetchOne($select);

$client = new class () extends QshprClient {
    public array $queue = [];
    public array $calls = [];

    public function __construct()
    {
    }

    public function getOrder(int $orderId, ?int $storeId = null): array
    {
        return $this->answer('get', $orderId);
    }

    public function getFees(array $query, ?int $storeId = null): array
    {
        return $this->answer('fees', $query);
    }

    public function createOrder(array $payload, ?int $storeId = null): array
    {
        return $this->answer('create', $payload);
    }

    public function confirmOrder(int $orderId, ?int $storeId = null): array
    {
        return $this->answer('confirm', $orderId);
    }

    public function cancelOrder(int $orderId, string $comment, ?int $storeId = null): array
    {
        return $this->answer('cancel', [$orderId, $comment]);
    }

    public function listWebhooks(?int $storeId = null): array
    {
        return $this->answer('list', null);
    }

    public function createWebhook(string $callbackUrl, ?int $storeId = null): int
    {
        return $this->answer('create_webhook', $callbackUrl);
    }

    public function updateWebhook(int $webhookId, string $callbackUrl, ?int $storeId = null): void
    {
        $this->answer('update_webhook', [$webhookId, $callbackUrl]);
    }

    private function answer(string $operation, mixed $request): mixed
    {
        $this->calls[] = [$operation, $request];
        $answer = array_shift($this->queue[$operation]) ?? null;
        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer ?? throw new \RuntimeException("unexpected $operation call");
    }
};
$notices = new class () implements NotifierInterface {
    public array $critical = [];

    public function add($severity, $title, $description, $url = '', $isInternal = true)
    {
        return $this;
    }

    public function addCritical($title, $description, $url = '', $isInternal = true)
    {
        $this->critical[] = [(string)$title, (string)$description];

        return $this;
    }

    public function addMajor($title, $description, $url = '', $isInternal = true)
    {
        return $this;
    }

    public function addMinor($title, $description, $url = '', $isInternal = true)
    {
        return $this;
    }

    public function addNotice($title, $description, $url = '', $isInternal = true)
    {
        return $this;
    }
};
share($om, QshprClient::class, $client);
// Shared under the class Magento's preference resolves the interface to.
share($om, $om->get(\Magento\Framework\ObjectManager\ConfigInterface::class)->getPreference(NotifierInterface::class), $notices);
share($om, Config::class, $config);

$operations = static fn (): string => implode(',', array_column($client->calls, 0));
$state = static fn (int $id, string $status, ?string $courier = null): array => ['success' => true,
    'order' => ['id' => $id, 'status' => $status, 'orderNo' => "SQ$id"],
    'courier' => $courier === null ? null : ['courierName' => $courier, 'courierPhoneNumber' => '555000000']];

/** POST the webhook with a JSON body; returns [HTTP status, body text]. */
$webhook = static function (array|string $body, ?string $key = KEY, array $queue = []) use ($om, $client, $notices): array {
    $client->queue = $queue;
    $client->calls = [];
    $notices->critical = [];
    $request = $om->create(HttpRequest::class);
    $request->setMethod('POST');
    if ($key !== null) {
        $request->setParam('key', $key);
    }
    $request->setContent(is_string($body) ? $body : json_encode($body));
    $result = $om->create(StatusWebhook::class, ['request' => $request])->execute();
    $response = $om->create(HttpResponse::class);
    $result->renderResult($response);

    return [$response->getHttpResponseCode(), (string)$response->getBody()];
};

if ($orderId === 0) {
    echo "SKIP  section 2: no Quickshipper order to ship on this store (place one at checkout first)\n";
} else {
    $log = $om->get(DeliveryLog::class);
    $order = $om->create(Order::class)->load($orderId);
    $incrementId = (string)$order->getIncrementId();
    $connection->beginTransaction();

    try {
        // Other deliveries are taken out of the cron's reach (rolled back with the rest).
        $connection->update($table, ['alerted_at' => new \Zend_Db_Expr('NOW()')], ['alerted_at IS NULL']);

        // A saved shipment for the order.
        $toShip = [];
        foreach ($order->getAllItems() as $item) {
            if (!$item->isDummy(true) && $item->getQtyToShip() > 0) {
                $toShip[$item->getId()] = $item->getQtyToShip();
            }
        }
        $shipment = $om->get(ShipmentFactory::class)->create($order, $toShip);
        $shipment->register();
        $order->setIsInProcess(true);
        $om->create(Transaction::class)->addObject($shipment)->addObject($order)->save();
        $shipmentId = (int)$shipment->getId();
        check('setup: a shipment saved for the order', $shipmentId > 0);

        /** A shipped delivery booked as Quickshipper order $id. */
        $shipped = static function (int $id) use ($log, $orderId, $incrementId, $shipmentId): int {
            $rowId = $log->recordBooking($orderId, $incrementId, 1, '85', 41.709, 44.776, 660,
                new Booking(2, 'Wolt', $id, "SQ$id", "https://test.quickshipper.ge/track/SQ$id", 550, 0, 660, false),
                DeliveryLog::STATE_DISPATCHED);
            $log->linkShipment($rowId, $shipmentId);

            return $rowId;
        };
        $row = static fn (int $rowId): array => $log->find($rowId) ?? [];
        $notes = static fn (): array => $connection->fetchCol($connection->select()
            ->from($resource->getTableName('sales_shipment_comment'), ['comment'])
            ->where('parent_id = ?', $shipmentId)->order('entity_id ASC'));
        $history = static fn (): string => implode(' | ', $connection->fetchCol($connection->select()
            ->from($resource->getTableName('sales_order_status_history'), ['comment'])->where('parent_id = ?', $orderId)));

        $rowId = $shipped(777040);

        // The key.
        [$code] = $webhook(['OrderId' => 777040, 'Status' => 'Assigned'], key: 'wrong');
        check('webhook: wrong key → 403, Quickshipper not asked', $code === 403 && $client->calls === []);
        [$code] = $webhook(['OrderId' => 777040], key: null);
        check('webhook: no key → 403', $code === 403 && $client->calls === []);
        $noKey = $om->create(Config::class, ['scopeConfig' => overrideScope([P . 'webhook_key' => ''], $realScope)]);
        $request = $om->create(HttpRequest::class)->setParam('key', '');
        $request->setContent('{"OrderId":777040}');
        $response = $om->create(HttpResponse::class);
        $om->create(StatusWebhook::class, ['request' => $request, 'config' => $noKey])->execute()->renderResult($response);
        check('webhook: store never connected (no key) → 403 even for an empty key', $response->getHttpResponseCode() === 403);

        // The body.
        [$code] = $webhook('not json');
        check('webhook: unreadable body → 400', $code === 400 && $client->calls === []);
        [$code] = $webhook(['Status' => 'Delivered']);
        check('webhook: no OrderId → 400', $code === 400);
        [$code, $text] = $webhook(['OrderId' => 999999999, 'Status' => 'Delivered']);
        check('webhook: not one of our bookings → 200, Quickshipper not asked', $code === 200 && $text === 'not_ours'
            && $client->calls === []);

        // Ours: the order is read from Quickshipper; the body's status is not believed.
        [$code, $text] = $webhook(['OrderId' => 777040, 'Status' => 'Delivered'],
            queue: ['get' => [$state(777040, 'Assigned', 'Giorgi')]]);
        $r = $row($rowId);
        check('webhook: re-reads the order, records Quickshipper\'s own status', $code === 200 && $text === 'updated'
            && $operations() === 'get' && $client->calls[0][1] === 777040 && $r['qshpr_status'] === 'Assigned'
            && $r['state'] === 'dispatched' && $r['courier_name'] === 'Giorgi', json_encode($r));
        check('webhook: shipment note with the courier', $notes() === ['Quickshipper: courier assigned (Giorgi).'],
            json_encode($notes()));

        [$code, $text] = $webhook(['OrderId' => 777040], queue: ['get' => [$state(777040, 'Assigned', 'Giorgi')]]);
        check('webhook: the same status again → 200, nothing added', $code === 200 && $text === 'unchanged'
            && count($notes()) === 1);
        [, $text] = $webhook(['OrderId' => 777040], queue: ['get' => [$state(777040, 'Assigned')]]);
        check('webhook: same status, no courier in the answer → no news, the name stays, no note',
            $text === 'unchanged' && count($notes()) === 1 && $row($rowId)['courier_name'] === 'Giorgi');

        [$code] = $webhook(['OrderId' => 777040], queue: ['get' => [new QshprApiException(__('Quickshipper request GET /v1/Order failed: timeout'))]]);
        check('webhook: Quickshipper not answering → 503 (it retries), nothing changed', $code === 503
            && $row($rowId)['qshpr_status'] === 'Assigned');

        $busy = new class () extends DeliveryLock {
            public function __construct()
            {
            }

            public function acquire(int $orderId, ?int $timeoutSeconds = null): bool
            {
                return false;
            }

            public function release(int $orderId): void
            {
            }
        };
        $client->calls = [];
        $busyResult = $om->create(StatusUpdater::class, ['lock' => $busy])->update(777040);
        check('update: order busy → Busy (webhook answers 503), Quickshipper not asked',
            $busyResult === UpdateResult::Busy && $client->calls === []);

        $webhook(['OrderId' => 777040], queue: ['get' => [$state(777040, 'OnTheWay', 'Giorgi')]]);
        $carrier = $om->get(CarrierFactory::class)->create('shuboqshpr', 1);
        $info = $carrier->getTrackingInfo('SQ777040');
        check('tracking popup: status next to the courier, link kept', $info
            && str_ends_with((string)$info->getCarrierTitle(), 'Wolt · On the way')
            && $info->getUrl() === 'https://test.quickshipper.ge/track/SQ777040', (string)$info?->getCarrierTitle());

        $webhook(['OrderId' => 777040], queue: ['get' => [$state(777040, 'OnTheWay', 'Nino')]]);
        $allNotes = $notes();
        check('webhook: another courier, same status → note names the new courier',
            end($allNotes) === 'Quickshipper: On the way (courier: Nino).' && $row($rowId)['courier_name'] === 'Nino',
            json_encode($allNotes));

        [$code, $text] = $webhook(['OrderId' => 777040], queue: ['get' => [$state(777040, 'Delivered', 'Nino')]]);
        $r = $row($rowId);
        $allNotes = $notes();
        check('webhook: delivered → final state, note, no alert', $text === 'updated' && $r['state'] === 'delivered'
            && end($allNotes) === 'Quickshipper: Delivered.' && $notices->critical === [], json_encode($allNotes));
        [$code, $text] = $webhook(['OrderId' => 777040, 'Status' => 'Cancelled']);
        check('webhook: after a final state → 200, Quickshipper not even asked', $code === 200 && $text === 'unchanged'
            && $client->calls === [] && $row($rowId)['state'] === 'delivered');

        // Shipped, then cancelled or failed: the merchant is told.
        $cancelledId = $shipped(777041);
        $webhook(['OrderId' => 777041], queue: ['get' => [$state(777041, 'Cancelled')]]);
        check('webhook: cancelled after shipping → state cancelled, admin notification, order comment',
            $row($cancelledId)['state'] === 'cancelled' && count($notices->critical) === 1
            && $notices->critical[0][0] === "Quickshipper delivery for order #$incrementId: Cancelled"
            && str_contains($history(), 'Quickshipper cancelled delivery SQ777041 after the order was shipped')
            && str_contains($history(), 'Book Courier Again on the shipment'),
            json_encode($notices->critical));
        $failedId = $shipped(777042);
        $webhook(['OrderId' => 777042], queue: ['get' => [$state(777042, 'DeliveryFailed')]]);
        check('webhook: delivery failed → state failed, merchant told', $row($failedId)['state'] === 'failed'
            && count($notices->critical) === 1 && str_contains($history(), 'delivery SQ777042 failed'));

        // The polling safety net.
        $fresh = $shipped(777043);
        $recent = $shipped(777044);
        $connection->update($table, ['status_checked_at' => new \Zend_Db_Expr('NOW() - INTERVAL 5 MINUTE')],
            ['entity_id = ?' => $recent]);
        $stale = $shipped(777045);
        $connection->update($table, ['qshpr_status' => 'Assigned',
            'status_changed_at' => new \Zend_Db_Expr('NOW() - INTERVAL 8 DAY'),
            'status_checked_at' => new \Zend_Db_Expr('NOW() - INTERVAL 1 HOUR')], ['entity_id = ?' => $stale]);
        $client->queue = ['get' => [$state(777043, 'ReadyForPickup')]];
        $client->calls = [];
        $notices->critical = [];
        $om->create(StatusSync::class)->execute();
        check('cron: asks about the never-checked delivery only', $operations() === 'get'
            && $client->calls[0][1] === 777043 && $row($fresh)['qshpr_status'] === 'ReadyForPickup', $operations());
        check('cron: recently checked one waits', $row($recent)['qshpr_status'] === null);
        check('cron: a week without news → reported once, no longer polled', $row($stale)['alerted_at'] !== null
            && count($notices->critical) === 1 && str_contains($history(), 'SQ777045 has had no status change for 7 days'),
            json_encode($notices->critical));
        $client->calls = [];
        $om->create(StatusSync::class)->execute();
        check('cron, next run: nothing due (fresh one just checked, stale one reported)', $client->calls === [],
            $operations());
        $connection->update($table, ['status_checked_at' => new \Zend_Db_Expr('NOW() - INTERVAL 20 MINUTE')],
            ['entity_id = ?' => $fresh]);
        $client->queue = ['get' => [$state(777043, 'ReadyForPickup'), $state(777044, 'Pickup')]];
        $connection->update($table, ['status_checked_at' => new \Zend_Db_Expr('NOW() - INTERVAL 20 MINUTE')],
            ['entity_id = ?' => $recent]);
        $om->create(StatusSync::class)->execute();
        check('cron: unchanged status only marks the check; a change is recorded',
            $row($fresh)['qshpr_status'] === 'ReadyForPickup' && $row($recent)['qshpr_status'] === 'Pickup'
            && $operations() === 'get,get', $operations());
        $failing = $shipped(777046);
        $quiet = $shipped(777047);
        $connection->update($table, ['created_at' => new \Zend_Db_Expr('NOW() - INTERVAL 8 DAY'),
            'status_checked_at' => new \Zend_Db_Expr('NOW() - INTERVAL 1 HOUR')], ['entity_id = ?' => $quiet]);
        $client->queue = ['get' => [new QshprApiException(__('Quickshipper request GET /v1/Order failed: timeout'))]];
        $client->calls = [];
        $notices->critical = [];
        $om->create(StatusSync::class)->execute();
        check('cron: a failing check counts as asked (waits its turn), nothing recorded',
            $operations() === 'get' && $row($failing)['status_checked_at'] !== null && $row($failing)['qshpr_status'] === null,
            $operations());
        check('cron: no status a week after booking → reported, though checks keep updated_at fresh',
            $row($quiet)['alerted_at'] !== null && count($notices->critical) === 1, json_encode($notices->critical));
        // --- Cancelling from the shipment page ---
        $tracks = static fn (): array => $connection->fetchCol($connection->select()
            ->from($resource->getTableName('sales_shipment_track'), ['track_number'])
            ->where('parent_id = ?', $shipmentId)->order('entity_id ASC'));
        /** A shipped delivery with its track on the shipment. */
        $shippedWithTrack = static function (int $id) use ($shipped, $om, $shipmentId, $orderId): int {
            $rowId = $shipped($id);
            $track = $om->get(TrackFactory::class)->create()->setParentId($shipmentId)->setOrderId($orderId)
                ->setCarrierCode('shuboqshpr')->setTitle('Quickshipper Wolt')->setTrackNumber("SQ$id");
            $om->get(ShipmentTrackRepositoryInterface::class)->save($track);

            return $rowId;
        };
        $canceller = $om->get(Canceller::class);
        $cancel = static function (array $queue) use ($canceller, $client, $shipmentId): array {
            $client->queue = $queue;
            $client->calls = [];
            try {
                return $canceller->cancelForShipment($shipmentId, 'Cancelled by the merchant in Magento');
            } catch (\Throwable $e) {
                return [$e, null, ''];
            }
        };

        $rowId = $shippedWithTrack(777050);
        [$outcome, , $status] = $cancel(['get' => [$state(777050, 'ReadyForPickup')], 'cancel' => [['success' => true]]]);
        $r = $row($rowId);
        check('cancel: before a courier → cancelled at Quickshipper with the reason', $outcome === CancelOutcome::Cancelled
            && $operations() === 'get,cancel' && $client->calls[1][1] === [777050, 'Cancelled by the merchant in Magento'],
            $operations());
        check('cancel: delivery row cancelled, track removed, shipment stays', $r['state'] === 'cancelled'
            && $r['qshpr_status'] === 'Cancelled' && !in_array('SQ777050', $tracks(), true)
            && (int)$r['shipment_id'] === $shipmentId, json_encode($tracks()));
        check('cancel: shipment note and order comment', in_array(
            'Quickshipper delivery SQ777050 cancelled from Magento (Cancelled by the merchant in Magento); '
            . 'its tracking number was removed.', $notes(), true)
            && str_contains($history(), 'Quickshipper delivery SQ777050 is cancelled. Quickshipper returns the delivery price (5.50 GEL)'));
        [$outcome] = $cancel([]);
        check('cancel: nothing under way any more → NothingToCancel, Quickshipper not asked',
            $outcome === CancelOutcome::NothingToCancel && $client->calls === []);

        $rowId = $shippedWithTrack(777051);
        [$outcome, , $status] = $cancel(['get' => [$state(777051, 'OnTheWay', 'Giorgi')]]);
        check('cancel: a courier has it → not cancelled, nothing changed, status told', $outcome === CancelOutcome::CourierHasIt
            && $status === 'OnTheWay' && $operations() === 'get' && $row($rowId)['state'] === 'dispatched'
            && in_array('SQ777051', $tracks(), true));
        [$outcome] = $cancel(['get' => [$state(777051, 'Cancelled')]]);
        check('cancel: already cancelled at Quickshipper → recorded, track removed, no cancel call',
            $outcome === CancelOutcome::WasCancelled && $operations() === 'get' && $row($rowId)['state'] === 'cancelled'
            && !in_array('SQ777051', $tracks(), true));

        $rowId = $shippedWithTrack(777052);
        [$outcome] = $cancel(['get' => [$state(777052, 'Delivered')]]);
        check('cancel: already delivered → Ended, recorded as delivered, noted on the shipment',
            $outcome === CancelOutcome::Ended && $row($rowId)['state'] === 'delivered'
            && in_array('Quickshipper: Delivered.', $notes(), true));

        $timeout = static fn (): QshprApiException => new QshprApiException(
            __('Quickshipper request POST /v1/Order/status failed: timeout')
        );
        $rowId = $shippedWithTrack(777054);
        [$outcome] = $cancel(['get' => [$state(777054, 'ReadyForPickup'), $state(777054, 'Cancelled')],
            'cancel' => [$timeout()]]);
        check('cancel: no answer, but the order now reads as cancelled → Cancelled, track removed',
            $outcome === CancelOutcome::Cancelled && $operations() === 'get,cancel,get'
            && $row($rowId)['state'] === 'cancelled' && !in_array('SQ777054', $tracks(), true), $operations());
        $rowId = $shippedWithTrack(777055);
        [$outcome] = $cancel(['get' => [$state(777055, 'ReadyForPickup'), $state(777055, 'ReadyForPickup')],
            'cancel' => [$timeout()]]);
        check('cancel: no answer, still under way → NoAnswer, nothing changed', $outcome === CancelOutcome::NoAnswer
            && $row($rowId)['state'] === 'dispatched' && in_array('SQ777055', $tracks(), true));
        [$outcome, , $status] = $cancel(['get' => [$state(777055, 'SomethingNew')]]);
        check('cancel: a status Magento does not know → UnknownStatus, not "a courier has it"',
            $outcome === CancelOutcome::UnknownStatus && $status === 'SomethingNew' && $operations() === 'get');
        $connection->update($table, ['state' => 'cancelled'], ['entity_id = ?' => $rowId]);

        $rowId = $shippedWithTrack(777053);
        [$error] = $cancel(['get' => [$state(777053, 'ReadyForPickup')], 'cancel' => [new QshprApiException(
            __('Quickshipper error 400 for POST /v1/Order/status: Order status change failed'), apiErrorCode: 400)]]);
        check('cancel: Quickshipper refuses → error, delivery and track unchanged', $error instanceof QshprApiException
            && $row($rowId)['state'] === 'dispatched' && in_array('SQ777053', $tracks(), true));
        $client->calls = [];
        [$outcome] = $om->create(Canceller::class, ['lock' => $busy])->cancelForShipment($shipmentId, 'x');
        check('cancel: order busy → Busy, Quickshipper not asked', $outcome === CancelOutcome::Busy && $client->calls === []);

        // --- The shipment page buttons ---
        $view = new class ($shipmentId) extends ShipmentView {
            public array $buttons = [];

            public function __construct(private readonly int $id)
            {
            }

            public function getShipment()
            {
                return new \Magento\Framework\DataObject(['id' => $this->id]);
            }

            public function addButton($buttonId, $data, $level = 0, $sortOrder = 0, $region = 'toolbar')
            {
                $this->buttons[$buttonId] = $data;

                return $this;
            }

            public function getUrl($route = '', $params = [])
            {
                return $route . '/shipment_id/' . ($params['shipment_id'] ?? '');
            }

            public function escapeJs($string)
            {
                return addslashes((string)$string);
            }

            public function escapeHtml($data, $allowedTags = null)
            {
                return htmlspecialchars((string)$data);
            }
        };
        $allowed = new class () implements AuthorizationInterface {
            public bool $allow = true;
            public array $asked = [];

            public function isAllowed($resource, $privilege = null)
            {
                $this->asked[] = $resource;

                return $this->allow;
            }
        };
        $buttons = $om->create(ShipmentViewButtons::class, ['authorization' => $allowed]);
        $layout = $om->get(LayoutInterface::class);
        $buttons->beforeSetLayout($view, $layout);
        check('buttons: delivery under way → Cancel, posting with the form key after a question',
            array_keys($view->buttons) === ['shubo_qshpr_cancel']
            && str_contains($view->buttons['shubo_qshpr_cancel']['onclick'], "deleteConfirm('Cancel Quickshipper delivery SQ777053?")
            && str_contains($view->buttons['shubo_qshpr_cancel']['onclick'], "shuboqshpr/delivery/cancel/shipment_id/$shipmentId', {data: {}})"),
            json_encode($view->buttons));
        $allowed->allow = false;
        $view->buttons = [];
        $buttons->beforeSetLayout($view, $layout);
        check('buttons: admin without shipping permission → none', $view->buttons === []);
        check('buttons and actions: the order "Ship" permission, as core\'s Submit Shipment',
            array_unique($allowed->asked) === ['Magento_Sales::ship'] && Cancel::ADMIN_RESOURCE === 'Magento_Sales::ship'
            && Rebook::ADMIN_RESOURCE === 'Magento_Sales::ship', json_encode(array_unique($allowed->asked)));

        // --- Book courier again ---
        $allowed->allow = true;
        $client->queue = ['get' => [$state(777053, 'ReadyForPickup')], 'cancel' => [['success' => true]]];
        $canceller->cancelForShipment($shipmentId, 'Cancelled by the merchant in Magento');
        $view->buttons = [];
        $buttons->beforeSetLayout($view, $layout);
        check('buttons: delivery cancelled → Book Courier Again', array_keys($view->buttons) === ['shubo_qshpr_rebook']);

        [, $method] = explode('_', (string)$order->getShippingMethod(), 2) + ['', ''];
        [$providerId, $priceKey] = explode('_', $method, 2) + ['', ''];
        $feesFor = static fn (float $amount): array => ['success' => true, 'fees' => [['providerId' => (int)$providerId,
            'providerName' => 'Check Courier', 'isActive' => true, 'serviceFee' => 0,
            'prices' => [['id' => $priceKey, 'amount' => $amount, 'currencyName' => 'GEL', 'deliverySpeedId' => null]]]]];
        $created = static fn (int $id): array => ['success' => true, 'orderId' => $id, 'orderStatus' => 'ReadyForPickup',
            'trackingUrl' => "https://test.quickshipper.ge/track/SQ$id", 'deliveryFee' => 7.0];
        $loadShipment = static fn (): Shipment => $om->create(Shipment::class)->load($shipmentId);
        $client->queue = ['fees' => [$feesFor(7.0)], 'create' => [$created(777060)],
            'get' => [['success' => true, 'order' => ['id' => 777060, 'orderNo' => 'SQ777060', 'orderPrice' => 7.0]]]];
        $client->calls = [];
        $booking = $om->get(Rebooker::class)->rebook($loadShipment());
        $new = $log->findLatestForShipment($shipmentId);
        check('book again: fresh price, new booking, nothing else', $operations() === 'fees,create,get'
            && $booking->qshprOrderNo === 'SQ777060', $operations());
        check('book again: new delivery on the same shipment, the customer\'s paid delivery (cancelled one returned its price)',
            (int)$new['qshpr_order_id'] === 777060 && $new['state'] === 'dispatched' && (int)$new['shipment_id'] === $shipmentId
            && (int)$new['paid_amount'] === 660 && !$booking->isExtraDelivery, json_encode($new));
        check('book again: new track saved, order comment and shipment note saved', in_array('SQ777060', $tracks(), true)
            && str_contains($history(), 'Courier booked with Quickshipper: Check Courier, order SQ777060, 7.00 GEL')
            && in_array('Quickshipper: courier booked again, order SQ777060 (Check Courier).', $notes(), true),
            json_encode($tracks()));
        try {
            $om->get(Rebooker::class)->rebook($loadShipment());
            check('book again: refused while a delivery is under way', false, 'no exception');
        } catch (DispatchException $e) {
            check('book again: refused while a delivery is under way', str_contains($e->getMessage(), 'no cancelled or failed'));
        }
        $connection->update($table, ['state' => 'failed'], ['qshpr_order_id = ?' => 777060]);
        $client->queue = ['fees' => [$feesFor(7.0)], 'create' => [$created(777061)],
            'get' => [['success' => true, 'order' => ['id' => 777061, 'orderNo' => 'SQ777061', 'orderPrice' => 7.0]]]];
        $booking = $om->get(Rebooker::class)->rebook($loadShipment());
        check('book again after a failed delivery → an extra delivery (customer paid 0 for it)',
            $booking->isExtraDelivery && (int)$log->findLatestForShipment($shipmentId)['paid_amount'] === 0);

        // --- Cancel on full refund ---
        $connection->delete($table, ['order_id = ?' => $orderId]);
        $refundRow = $shippedWithTrack(777070);
        $refunded = $om->create(Order::class)->load($orderId);
        $refunded->setBaseTotalPaid(20.0)->setBaseTotalRefunded(20.0);
        $refundEvent = static fn (Order $o): Observer => new Observer(['event' => new Event([
            'creditmemo' => $om->create(Creditmemo::class)->setOrder($o),
        ])]);
        $client->queue = ['get' => [$state(777070, 'ReadyForPickup')], 'cancel' => [['success' => true]]];
        $client->calls = [];
        $notices->critical = [];
        $om->create(CancelOnRefund::class)->execute($refundEvent($refunded));
        check('refund: full refund, no courier yet → delivery cancelled, nobody alerted', $operations() === 'get,cancel'
            && $row($refundRow)['state'] === 'cancelled' && $notices->critical === []);
        $refundRow = $shippedWithTrack(777071);
        $client->queue = ['get' => [$state(777071, 'Pickup', 'Giorgi')]];
        $om->create(CancelOnRefund::class)->execute($refundEvent($refunded));
        check('refund: a courier has it → merchant told (notification + order comment)', $row($refundRow)['state'] === 'dispatched'
            && count($notices->critical) === 1
            && str_contains($history(), 'SQ777071 could not be cancelled (Picked up by the courier)'),
            json_encode($notices->critical));
        $refundRow = $shippedWithTrack(777072);
        $client->queue = ['get' => [$state(777072, 'Delivered')]];
        $notices->critical = [];
        $om->create(CancelOnRefund::class)->execute($refundEvent($refunded));
        check('refund: already delivered (a return) → nobody alerted, recorded as delivered',
            $notices->critical === [] && $row($refundRow)['state'] === 'delivered', json_encode($notices->critical));
        $client->calls = [];
        $partly = (clone $refunded)->setBaseTotalRefunded(5.0);
        $om->create(CancelOnRefund::class)->execute($refundEvent($partly));
        $offConfig = $om->create(Config::class, ['scopeConfig' => overrideScope([P . 'cancel_on_refund' => '0'] + STORE, $realScope)]);
        $om->create(CancelOnRefund::class, ['config' => $offConfig])->execute($refundEvent($refunded));
        check('refund: partial refund, or the setting off → nothing', $client->calls === []);
    } finally {
        $connection->rollBack();
    }

    check('rolled back: no delivery rows of this run left',
        $connection->fetchOne($connection->select()->from($table, ['COUNT(*)'])
            ->where('qshpr_order_id BETWEEN 777040 AND 777079')) === '0');
}

// --- 3. Registering the webhook (stub client, stub config writer) ---
$writer = new class () implements WriterInterface {
    public array $saved = [];

    public function save($path, $value, $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeId = 0)
    {
        $this->saved[$path] = $value;
    }

    public function delete($path, $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeId = 0)
    {
    }
};
$reinit = new class () implements ReinitableConfigInterface {
    public function reinit()
    {
        return $this;
    }

    public function setValue($path, $value, $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
    }

    public function getValue($path, $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return null;
    }

    public function isSetFlag($path, $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return false;
    }
};
$storeId = (int)$om->get(StoreManagerInterface::class)->getDefaultStoreView()->getId();
$base = rtrim($om->get(StoreManagerInterface::class)->getStore($storeId)->getBaseUrl(), '/') . '/qshpr/webhook/status';
$registrar = static fn (Config $useConfig): WebhookRegistrar => $om->create(WebhookRegistrar::class, [
    'config' => $useConfig,
    'configWriter' => $writer,
    'reinitableConfig' => $reinit,
]);

$client->queue = ['list' => [[['id' => 5, 'callBackUrl' => 'https://elsewhere.test/qshpr/webhook/status?key=x']]],
    'create_webhook' => [1100]];
$client->calls = [];
$noKeyConfig = $om->create(Config::class, ['scopeConfig' => overrideScope([P . 'webhook_key' => ''], $realScope)]);
$connected = $registrar($noKeyConfig)->connect($storeId);
$savedKey = (string)$encryptor->decrypt((string)($writer->saved[P . 'webhook_key'] ?? ''));
$sentUrl = (string)($client->calls[1][1] ?? '');
check('register: first time → a key is made and stored encrypted', strlen($savedKey) === 40
    && ($writer->saved[P . 'webhook_key'] ?? '') !== $savedKey);
check('register: another shop\'s address is left alone; a new webhook is created with our URL and key',
    $operations() === 'list,create_webhook' && $sentUrl === $base . '?key=' . $savedKey
    && $connected === ['action' => 'created', 'webhookId' => 1100, 'url' => $base], $sentUrl . ' ' . json_encode($connected));

$client->queue = ['list' => [[['id' => 1081, 'callBackUrl' => $base . '?key=old'], ['id' => 7, 'callBackUrl' => 'x']]],
    'update_webhook' => [true]];
$client->calls = [];
$writer->saved = [];
$connected = $registrar($config)->connect($storeId);
check('register: our address registered before → re-pointed with the current key, no new key made',
    $operations() === 'list,update_webhook' && $client->calls[1][1] === [1081, $base . '?key=' . KEY]
    && $writer->saved === [] && $connected['action'] === 'updated', json_encode($client->calls));

// --- 4. Log ---
$logText = substr((string)file_get_contents($logFile), $logOffset);
check('log: refused key, status change, final state', str_contains($logText, 'webhook: refused a call with a missing or wrong key')
    && ($orderId === 0 || (str_contains($logText, 'SQ777040 is now Assigned')
        && str_contains($logText, 'SQ777040 is now Delivered (final: delivered)'))));
check('log: webhook key never logged', !str_contains($logText, KEY) && ($savedKey === '' || !str_contains($logText, $savedKey)));
if ($orderId > 0) {
    check('log: courier phone never logged', !str_contains($logText, '555000000'));
}

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
