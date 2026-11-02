<?php
// Checks for booking the courier from a shipment: Dispatch\Dispatcher,
// Dispatch\OrderPayloadBuilder, Dispatch\DeliveryLock, Plugin\ShipmentRegisterPlugin,
// the delivery record (Delivery\DeliveryLog, Observer\RecordDelivery,
// Observer\LinkShipment, Observer\ReleaseDeliveryLock), the clean-up cron
// (Cron\CleanUpDeliveries) and the carrier's tracking link.
//  1. pieces: ChosenCourier, Offer::priceKey, WeightConverter, OrderPayloadBuilder, OrderStatus
//  2. the real admin flow on a Quickshipper order of this store — ShipmentFactory
//     → Shipment::register() (the plugin books) → save — over a STUB Quickshipper
//     client, all inside a database transaction that is rolled back at the end:
//     normal booking, Draft confirm, refused confirm and its retry, reuse after a
//     failed save, bookings cancelled at Quickshipper, the per-order lock, the
//     clean-up cron, and automatic booking once paid (trigger, retries, alert,
//     a saved shipment), a shipment refused when its order was shipped meanwhile
//  3. log assertions on var/log/shubo_qshpr.log (this run's lines only)
//
// Nothing is sent to Quickshipper and nothing stays in the database. Section 2
// needs one order placed with Quickshipper that still has items to ship (the
// checkout browser test makes one); QSHPR_ORDER=<increment id> picks it.
//
// Run inside phpfpm from the Magento root: php <module-dir>/dev/checks/qshpr-dispatch-check.php

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\Manager;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Shipping\Model\CarrierFactory;
use Shubo\Qshpr\Cron\AutoDispatch;
use Shubo\Qshpr\Cron\CleanUpDeliveries;
use Shubo\Qshpr\Exception\DispatchException;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Delivery\Booking;
use Shubo\Qshpr\Model\Delivery\ChosenCourier;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Dispatch\AutoDispatchTrigger;
use Shubo\Qshpr\Model\Dispatch\AutoShipper;
use Shubo\Qshpr\Model\Dispatch\DeliveryLock;
use Shubo\Qshpr\Model\Dispatch\Dispatcher;
use Shubo\Qshpr\Model\Dispatch\OrderPayloadBuilder;
use Shubo\Qshpr\Model\Geocoding\Address;
use Shubo\Qshpr\Model\Geocoding\Geocoder;
use Shubo\Qshpr\Model\Geocoding\Location;
use Shubo\Qshpr\Model\Geocoding\Precision;
use Shubo\Qshpr\Model\Money;
use Shubo\Qshpr\Model\OrderStatus;
use Shubo\Qshpr\Model\QshprClient;
use Shubo\Qshpr\Model\Rates\CarDeliveryRule;
use Shubo\Qshpr\Model\Rates\Offer;
use Shubo\Qshpr\Model\WeightConverter;
use Shubo\Qshpr\Observer\MarkPaidForDispatch;
use Shubo\Qshpr\Observer\RecordDelivery;
use Shubo\Qshpr\Observer\RecordMultishippingDeliveries;
use Shubo\Qshpr\Observer\ReleaseDeliveryLock;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
// Load the admin area's DI and plugins, as an admin request would.
$om->get(ScopeInterface::class)->setCurrentScope('adminhtml');
$om->configure($om->get(ConfigLoaderInterface::class)->load('adminhtml'));

require __DIR__ . '/_stubs.php';

$logFile = BP . '/var/log/shubo_qshpr.log';
$logOffset = is_file($logFile) ? (int)filesize($logFile) : 0;

const P = 'carriers/shuboqshpr/';
const STORE = [
    P . 'pickup_latitude' => '41.6934',
    P . 'pickup_longitude' => '44.8015',
    'shipping/origin/street_line1' => 'Freedom Square 1',
    'shipping/origin/city' => 'Tbilisi',
    'general/store_information/name' => 'Check Shop',
    'general/store_information/phone' => '322000000',
    'general/locale/weight_unit' => 'lbs',
    P . 'auto_dispatch' => '1',
];

/**
 * Make the object manager hand out $instance for $type. Dev checks only: the
 * framework has no public API for it, so the protected registry is set.
 */
function share($om, string $type, object $instance): void
{
    (function () use ($type, $instance): void {
        $this->_sharedInstances[ltrim($type, '\\')] = $instance;
    })->call($om);
}

$realScope = $om->get(ScopeConfigInterface::class);
$config = $om->create(Config::class, ['scopeConfig' => overrideScope(STORE, $realScope)]);

// --- 1. Pieces ---
$parsed = ChosenCourier::fromShippingMethod('shuboqshpr_2_asap');
check('method code: courier and price read back', $parsed?->providerId === 2 && $parsed->priceKey === 'asap');
check('method code: OnWay bracket', ChosenCourier::fromShippingMethod('shuboqshpr_25_85')?->priceKey === '85');
check('method code: other carriers ignored', ChosenCourier::fromShippingMethod('flatrate_flatrate') === null);
check('method code: malformed ignored', ChosenCourier::fromShippingMethod('shuboqshpr_x_y') === null
    && ChosenCourier::fromShippingMethod('shuboqshpr_2') === null
    && ChosenCourier::fromShippingMethod('shuboqshpr_2_') === null);
check('money: lari → tetri rounds to the nearest tetri; tetri → "5.50"', Money::toTetri('6.6') === 660
    && Money::toTetri(5.505) === 551 && Money::toTetri(0) === 0 && Money::toGel(550) === '5.50'
    && Money::toGel(0) === '0.00' && Money::toGel(123456) === '1234.56');
check('method code: read from the order; none for a virtual order (no method)',
    ChosenCourier::fromOrder($om->create(Order::class)->setShippingMethod('shuboqshpr_25_85'))?->providerId === 25
    && ChosenCourier::fromOrder($om->create(Order::class)) === null);
check('price key: Glovo "ASAP" matches the method code form', Offer::priceKey('ASAP') === 'asap'
    && Offer::priceKey('Custom-Date') === 'customdate');

$kgConfig = $om->create(Config::class, ['scopeConfig' => overrideScope(['general/locale/weight_unit' => 'kgs'] + STORE, $realScope)]);
check('weight: lbs → kg', $om->create(WeightConverter::class, ['config' => $config])->toKg(2.2) === 0.998);
check('weight: kgs unchanged', $om->create(WeightConverter::class, ['config' => $kgConfig])->toKg(2.2) === 2.2);

$address = $om->create(OrderAddress::class)->setData([
    'street' => "Iakob Nikoladze St 7\nFlat 3, floor 2",
    'city' => 'Tbilisi',
    'country_id' => 'GE',
    'firstname' => 'Test',
    'lastname' => 'Customer',
    'telephone' => ' 591 000 000 ',
]);
$builder = static fn (array $o = []): OrderPayloadBuilder => $om->create(OrderPayloadBuilder::class, [
    'config' => $om->create(Config::class, ['scopeConfig' => overrideScope($o + STORE, $realScope)]),
]);
$payload = $builder()->build($address, 41.709, 44.776, 2, 'asap', null, '000000099', 34.0, 0.0, 1);
check('payload: pickup from Shipping Origin, pickup coordinates, Store Information', $payload['pickUpInfo'] === [
    'address' => 'Freedom Square 1', 'city' => 'Tbilisi', 'latitude' => 41.6934, 'longitude' => 44.8015,
    'name' => 'Check Shop', 'phonePrefix' => '+995', 'phone' => '322000000',
], json_encode($payload['pickUpInfo']));
check('payload: drop-off = street line 1, city, quoted point, name', $payload['dropOffInfo']['address'] === 'Iakob Nikoladze St 7'
    && $payload['dropOffInfo']['city'] === 'Tbilisi' && $payload['dropOffInfo']['latitude'] === 41.709
    && $payload['dropOffInfo']['longitude'] === 44.776 && $payload['dropOffInfo']['name'] === 'Test Customer');
check('payload: street line 2 → address comment', ($payload['dropOffInfo']['addressComment'] ?? null) === 'Flat 3, floor 2');
check('payload: phone as entered (trimmed), +995 prefix', $payload['dropOffInfo']['phone'] === '591 000 000'
    && $payload['dropOffInfo']['phonePrefix'] === '+995');
check('payload: courier + price id, no speed id when null', $payload['provider'] === ['providerId' => 2, 'providerFeeId' => 'asap']);
check('payload: Magento order number, cart amount, no weight when 0', $payload['integrationOrderId'] === '000000099'
    && $payload['cartAmount'] === 34.0 && !array_key_exists('cartWeight', $payload));
$withWeight = $builder()->build($address, 41.7, 44.7, 25, '85', 85, '1', 10.0, 1.5, 1);
check('status: Quickshipper names read, unknown → null', OrderStatus::fromAnswer('Draft') === OrderStatus::Draft
    && OrderStatus::fromAnswer('Bogus') === null && OrderStatus::fromAnswer(null) === null);
check('status: cancellable before a courier, over without delivery', OrderStatus::ReadyForPickup->isBeforeCourier()
    && OrderStatus::WaitingForCourier->isBeforeCourier() && !OrderStatus::Draft->isBeforeCourier()
    && !OrderStatus::Assigned->isBeforeCourier() && OrderStatus::Cancelled->isEndedWithoutDelivery()
    && OrderStatus::DeliveryFailed->isEndedWithoutDelivery() && !OrderStatus::Delivered->isEndedWithoutDelivery());
check('payload: speed id and weight when known', $withWeight['provider']['deliverySpeedId'] === 85 && $withWeight['cartWeight'] === 1.5);
check('payload: no cashOnDelivery unless the order is paid in cash', !array_key_exists('cashOnDelivery', $withWeight));
check('payload: cashOnDelivery from tetri to lari',
    ($builder()->build($address, 41.7, 44.7, 25, '85', 85, '1', 10.0, 1.5, 1,
        cashOnDelivery: ['deliveryPrice' => 660, 'parcelPrice' => 3333])['cashOnDelivery'] ?? null)
        === ['deliveryPrice' => 6.6, 'parcelPrice' => 33.33]);
check('payload: no carDelivery unless asked', !array_key_exists('carDelivery', $withWeight));
check('payload: carDelivery true when asked',
    ($builder()->build($address, 41.7, 44.7, 2, 'asap', null, '1', 10.0, 12.0, 1, carDelivery: true)['carDelivery'] ?? null) === true);

$expectDispatch = static function (callable $fn, string $needle, string $name): void {
    try {
        $fn();
        check($name, false, 'no exception');
    } catch (DispatchException $e) {
        check($name, str_contains($e->getMessage(), $needle), $e->getMessage());
    }
};
$expectDispatch(fn () => $builder(['general/store_information/phone' => ''])->build($address, 1, 1, 2, 'asap', null, '1', 1, 0, 1),
    'missing: store phone.', 'payload: no store phone → clear error');
$expectDispatch(fn () => $builder([P . 'pickup_latitude' => ''])->build($address, 1, 1, 2, 'asap', null, '1', 1, 0, 1),
    'missing: pickup latitude.', 'payload: no pickup point → clear error');
$expectDispatch(fn () => $builder(['general/store_information/name' => ''])->build($address, 1, 1, 2, 'asap', null, '1', 1, 0, 1),
    'missing: store name.', 'payload: no store name (never checked before) → clear error');
$noPhone = (clone $address)->setTelephone('');
$expectDispatch(fn () => $builder()->build($noPhone, 1, 1, 2, 'asap', null, '1', 1, 0, 1),
    'no phone', 'payload: no customer phone → clear error');

// --- 2. Admin flow over a stub client, rolled back ---
$resource = $om->get(ResourceConnection::class);
$connection = $resource->getConnection();
$orderTable = $resource->getTableName('sales_order');
$wanted = (string)getenv('QSHPR_ORDER');
$select = $connection->select()->from(['o' => $orderTable], ['entity_id'])
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

if ($orderId === 0) {
    echo "SKIP  section 2: no Quickshipper order to ship on this store (place one at checkout first)\n";
} else {
    $client = new class () extends QshprClient {
        public array $queue = [];
        public array $calls = [];

        public function __construct()
        {
        }

        public function getFees(array $query, ?int $storeId = null): array
        {
            return $this->answer('fees', $query);
        }

        public function createOrder(array $payload, ?int $storeId = null): array
        {
            return $this->answer('create', $payload);
        }

        public function getOrder(int $orderId, ?int $storeId = null): array
        {
            return $this->answer('get', $orderId);
        }

        public function confirmOrder(int $orderId, ?int $storeId = null): array
        {
            return $this->answer('confirm', $orderId);
        }

        public function cancelOrder(int $orderId, string $comment, ?int $storeId = null): array
        {
            return $this->answer('cancel', [$orderId, $comment]);
        }

        public function getAccount(?int $storeId = null): array
        {
            throw new \RuntimeException('unexpected getAccount() call');
        }

        private function answer(string $operation, mixed $request): array
        {
            $this->calls[] = [$operation, $request];
            $answer = array_shift($this->queue[$operation]) ?? null;
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer ?? throw new \RuntimeException("unexpected $operation call");
        }
    };
    $messages = new class () extends Manager {
        public array $added = [];

        public function __construct()
        {
        }

        public function addSuccessMessage($message, $group = null)
        {
            $this->added[] = ['success', (string)$message];

            return $this;
        }

        public function addWarningMessage($message, $group = null)
        {
            $this->added[] = ['warning', (string)$message];

            return $this;
        }
    };
    $point = new Location(41.7010, 44.7960, Precision::House, 'Tbilisi', 'stub');
    $geocoder = new class ($point) extends Geocoder {
        public int $calls = 0;

        public function __construct(private readonly Location $point)
        {
        }

        public function locate(Address $address, ?int $storeId = null): ?Location
        {
            $this->calls++;

            return $this->point;
        }
    };
    share($om, QshprClient::class, $client);
    share($om, Manager::class, $messages);
    share($om, Geocoder::class, $geocoder);
    share($om, Config::class, $config);
    // The real rule, which a check can overrule ($force) to book with a car.
    $carRule = new class ($config, $om->get(\Magento\Catalog\Model\ResourceModel\Product::class)) extends CarDeliveryRule {
        public ?bool $force = null;
        public ?bool $answered = null;

        public function forShipment(\Magento\Sales\Model\Order\Shipment $shipment, int $storeId, float $weightKg): bool
        {
            return $this->answered = $this->force ?? parent::forShipment($shipment, $storeId, $weightKg);
        }
    };
    share($om, CarDeliveryRule::class, $carRule);

    $log = $om->get(DeliveryLog::class);
    $table = $resource->getTableName(DeliveryLog::TABLE);
    $fees = static fn (array $prices, array $extra = []): array => ['success' => true, 'fees' => array_merge([
        ['providerId' => 25, 'providerName' => 'OnWay', 'isActive' => true, 'serviceFee' => 0.6, 'prices' => $prices],
    ], $extra)];
    $onWay85 = ['id' => '85', 'amount' => 6.6, 'currencyName' => 'GEL', 'deliverySpeedId' => 85];
    $wolt = ['providerId' => 2, 'providerName' => 'Wolt', 'isActive' => true, 'serviceFee' => 0.6,
        'prices' => [['id' => 'asap', 'amount' => 5.5, 'currencyName' => 'GEL', 'deliverySpeedId' => null]]];
    $created = static fn (int $id): array => ['success' => true, 'orderId' => $id, 'orderStatus' => 'ReadyForPickup',
        'trackingUrl' => "https://test.quickshipper.ge/track/SQ$id", 'deliveryFee' => 6.6];
    $details = static fn (int $id, float $price): array => ['success' => true, 'order' => ['id' => $id,
        'orderNo' => "SQ$id", 'orderPrice' => $price, 'serviceFee' => 0.6,
        'trackingUrl' => "https://test.quickshipper.ge/track/SQ$id"]];

    $lock = $om->get(DeliveryLock::class);

    /** The order loaded fresh and a new shipment of everything still to ship, not registered. */
    $newShipment = static function (?string $method = null) use ($om, $orderId): array {
        $order = $om->create(Order::class)->load($orderId);
        if ($method !== null) {
            $order->setShippingMethod($method);
        }
        $toShip = [];
        foreach ($order->getAllItems() as $item) {
            $item->setWeight(2.2);  // lbs → 0.998 kg per unit
            if (!$item->isDummy(true) && $item->getQtyToShip() > 0) {
                $toShip[$item->getId()] = $item->getQtyToShip();
            }
        }

        // As the admin form does: Magento ships only the items it is given.
        return [$order, $om->get(ShipmentFactory::class)->create($order, $toShip)];
    };

    /**
     * Register a new shipment (the plugin books). The order lock a booking
     * keeps until the save commits is released afterwards, as the end of the
     * request would, unless $keepLock.
     */
    $ship = static function (array $queue, ?string $method = null, bool $keepLock = false) use (
        $newShipment,
        $client,
        $messages,
        $lock,
    ): array {
        $client->queue = $queue + ['fees' => [], 'create' => [], 'get' => [], 'confirm' => [], 'cancel' => []];
        $client->calls = [];
        $messages->added = [];
        [$order, $shipment] = $newShipment($method);
        try {
            $shipment->register();
            $error = null;
        } catch (\Throwable $e) {
            $error = $e;
        }
        $lockedOrder = (int)$shipment->getData(Dispatcher::SHIPMENT_LOCKED_ORDER);
        if ($lockedOrder > 0 && !$keepLock) {
            $lock->release($lockedOrder);
        }

        return [$order, $shipment, $error];
    };
    $tracks = static fn ($shipment): array => array_map(
        static fn ($t): string => $t->getCarrierCode() . ':' . $t->getTrackNumber(),
        $shipment->getAllTracks()
    );
    $comments = static fn (Order $order): string => implode(' | ', array_map(
        static fn ($h): string => (string)$h->getComment(),
        $order->getStatusHistories() ?? []
    ));
    $operations = static fn (): string => implode(',', array_column($client->calls, 0));
    $rows = static fn (): array => $connection->fetchAll(
        $connection->select()->from($table)->where('order_id = ?', $orderId)->order('entity_id ASC')
    );
    $resetRows = static function (bool $quoted = true) use ($connection, $table, $orderId, $log, $om): int {
        $connection->delete($table, ['order_id = ?' => $orderId]);
        if (!$quoted) {
            return 0;
        }
        $order = $om->create(Order::class)->load($orderId);

        return $log->recordQuote($orderId, (string)$order->getIncrementId(), (int)$order->getStoreId(), 25, '85',
            41.709, 44.776, 660);
    };

    $rowsBefore = $rows();
    $connection->beginTransaction();

    try {
        // Order placement records the choice (observer).
        $resetRows(quoted: false);
        $placed = $om->create(Order::class)->load($orderId);
        $om->create(RecordDelivery::class)->execute(new Observer(['event' => new Event(['order' => $placed])]));
        $row = $rows()[0] ?? [];
        // The courier and price key come from the order's own method code
        // (shuboqshpr_<provider>_<priceKey>); the sandbox's price keys vary.
        [, $placedProvider, $placedPriceKey] = explode('_', (string)$placed->getShippingMethod(), 3) + ['', '', ''];
        check('placement: delivery row with courier, price, point and paid amount', ($row['state'] ?? '') === 'quoted'
            && (string)$row['provider_id'] === $placedProvider && $row['price_key'] === $placedPriceKey
            && (float)$row['dropoff_latitude'] === 41.701 && (int)$row['paid_amount'] === (int)round((float)$placed->getBaseShippingAmount() * 100),
            json_encode($row));
        $otherOrder = (clone $placed)->setShippingMethod('flatrate_flatrate');
        $before = count($rows());
        $om->create(RecordDelivery::class)->execute(new Observer(['event' => new Event(['order' => $otherOrder])]));
        check('placement: other carriers are not recorded', count($rows()) === $before);
        $om->create(RecordDelivery::class)->execute(new Observer(['event' => new Event(['order' => $placed])]));
        check('placement: recorded once (a second event adds nothing)', count($rows()) === 1);
        $multi = $om->create(RecordMultishippingDeliveries::class);
        $multi->execute(new Observer(['event' => new Event(['order' => $placed])]));
        check('multiple addresses: one-page "order" in checkout_submit_all_after left to the placement event',
            count($rows()) === 1);
        $resetRows(quoted: false);
        $multi->execute(new Observer(['event' => new Event(['orders' => [$placed, $otherOrder]])]));
        check('multiple addresses: each Quickshipper order of "orders" recorded, others not',
            count($rows()) === 1 && $rows()[0]['state'] === 'quoted', json_encode($rows()));

        // The stub answers below describe OnWay (25) with price key 85: give the
        // order that method whatever courier it was placed with (rolled back).
        $connection->update($orderTable, ['shipping_method' => 'shuboqshpr_25_85'], ['entity_id = ?' => $orderId]);

        // Not a Quickshipper order: nothing happens.
        [, $shipment, $error] = $ship([], 'flatrate_flatrate');
        check('other carrier: no Quickshipper call, no track, no message', $error === null && $client->calls === []
            && $tracks($shipment) === [] && $messages->added === []);

        // Courier no longer offered.
        $quotedId = $resetRows();
        [, $shipment, $error] = $ship(['fees' => [$fees([], [$wolt])]]);
        check('courier gone: shipment stopped with a clear error', $error instanceof DispatchException
            && str_contains($error->getMessage(), 'no longer offers'), (string)$error?->getMessage());
        check('courier gone: nothing booked', $operations() === 'fees' && $tracks($shipment) === []);

        // Price check fails (e.g. the sandbox's slow first answer).
        [, , $error] = $ship(['fees' => [new QshprApiException(__('Operation timed out after 10001 milliseconds'))]]);
        check('price check timeout: stopped, "try again"', $error instanceof DispatchException
            && str_contains($error->getMessage(), 'Try again') && $operations() === 'fees', (string)$error?->getMessage());

        // Cash on delivery: the order's payment becomes Magento's "Cash On
        // Delivery" (rolled back with everything else).
        $paymentTable = $resource->getTableName('sales_order_payment');
        $paidWith = (string)$connection->fetchOne(
            $connection->select()->from($paymentTable, ['method'])->where('parent_id = ?', $orderId)
        );
        $connection->update($paymentTable, ['method' => 'cashondelivery'], ['parent_id = ?' => $orderId]);
        [, $shipment, $error] = $ship(['fees' => [$fees([$onWay85])]]);
        check('cod: courier that cannot collect cash → shipment stopped, nothing booked',
            $error instanceof DispatchException && str_contains($error->getMessage(), 'cannot collect cash')
                && $operations() === 'fees', (string)$error?->getMessage());
        $codOnWay = ['providerId' => 25, 'providerName' => 'OnWay', 'isActive' => true, 'serviceFee' => 0.6,
            'hasCashOnDelivery' => true, 'prices' => [$onWay85]];
        [$codOrder, , $error] = $ship([
            'fees' => [['success' => true, 'fees' => [$codOnWay]]],
            'create' => [new QshprApiException(__('Quickshipper error 400 for POST /v1/Order: test stop'), apiErrorCode: 400)],
        ]);
        $goods = 0.0;
        foreach ($codOrder->getAllItems() as $item) {
            if (!$item->isDummy(true)) {
                $goods += (float)$item->getBaseRowTotalInclTax() - (float)$item->getBaseDiscountAmount();
            }
        }
        $cashSent = $client->calls[1][1]['cashOnDelivery'] ?? null;
        check('cod: booking asks the courier to collect the goods and the delivery the customer was charged',
            $cashSent === ['deliveryPrice' => 6.6, 'parcelPrice' => round($goods, 2)], json_encode($cashSent));
        $connection->update($paymentTable, ['method' => $paidWith], ['parent_id = ?' => $orderId]);

        check('car: the order\'s products need no car (default off, nothing set)', $carRule->answered === false);

        // Quickshipper refuses the booking (asked with a car).
        $carRule->force = true;
        [, $shipment, $error] = $ship([
            'fees' => [$fees([$onWay85])],
            'create' => [new QshprApiException(__('Quickshipper error 400 for POST /v1/Order: Not enough balance'), apiErrorCode: 400)],
        ]);
        check('refused: stopped with Quickshipper\'s reason', $error instanceof DispatchException
            && str_contains($error->getMessage(), 'Not enough balance')
            && str_contains($error->getMessage(), 'cancelled order'), (string)$error?->getMessage());
        check('refused: no track, row still quoted', $tracks($shipment) === [] && $rows()[0]['state'] === 'quoted');
        check('car: price check asked CarDelivery=true', ($client->calls[0][1]['CarDelivery'] ?? null) === 'true');
        check('car: booking asked carDelivery: true', ($client->calls[1][1]['carDelivery'] ?? null) === true);
        $carRule->force = null;

        // A delivery time the customer chose at checkout.
        $stop = static fn (): QshprApiException => new QshprApiException(__('Quickshipper error 400 for POST /v1/Order: test stop'), apiErrorCode: 400);
        $future = gmdate('Y-m-d\\TH:i:s\\Z', time() + 86400);
        $connection->update($table, ['scheduled_time' => $future], ['entity_id = ?' => $quotedId]);
        $customPrice = ['id' => 'custom', 'amount' => 6.6, 'currencyName' => 'GEL', 'deliverySpeedId' => null];
        $ship(['fees' => [$fees([$customPrice])], 'create' => [$stop()]]);
        check('time: price asked for that courier at the chosen time',
            ($client->calls[0][1]['ScheduleDelivery.ProviderId'] ?? null) === 25
                && ($client->calls[0][1]['ScheduleDelivery.ScheduledDate'] ?? null) === $future, json_encode($client->calls[0][1] ?? []));
        check('time: the scheduled price is booked with scheduledTime',
            ($client->calls[1][1]['scheduledTime'] ?? null) === $future
                && ($client->calls[1][1]['provider']['providerFeeId'] ?? null) === 'custom');
        $connection->update($table, ['scheduled_time' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 3600)], ['entity_id = ?' => $quotedId]);
        [$latePlaced] = $ship(['fees' => [$fees([$onWay85])], 'create' => [$stop()]]);
        check('time passed: booked as soon as possible', !array_key_exists('ScheduleDelivery.ProviderId', $client->calls[0][1])
            && !array_key_exists('scheduledTime', $client->calls[1][1]));
        check('time passed: the merchant is told, in store time', str_contains($comments($latePlaced), 'has passed')
            && str_contains($comments($latePlaced), 'store time') && !str_contains($comments($latePlaced), 'UTC'),
            $comments($latePlaced));
        $connection->update($table, ['scheduled_time' => null], ['entity_id = ?' => $quotedId]);

        // Booked; Quickshipper charged more than the customer paid (OnWay bracket change 85 → 86).
        [$order, $shipment, $error] = $ship([
            'fees' => [$fees([['id' => '86', 'amount' => 8.6, 'currencyName' => 'GEL', 'deliverySpeedId' => 86]])],
            'create' => [$created(777002)],
            'get' => [$details(777002, 8.6)],
        ]);
        $sent = $client->calls[1][1] ?? [];
        check('bracket change: the courier\'s only price is booked', $error === null
            && ($sent['provider'] ?? null) === ['providerId' => 25, 'providerFeeId' => '86', 'deliverySpeedId' => 86],
            (string)$error?->getMessage() . json_encode($sent['provider'] ?? null));
        check('price rise: admin warned with both amounts', in_array(
            ['warning', 'Quickshipper charged 8.60 GEL for this delivery; the customer paid 6.60 GEL.'],
            $messages->added,
            true
        ), json_encode($messages->added));

        // Booked, but reading the order back fails: the booking stands.
        $resetRows();
        [, $shipment, $error] = $ship([
            'fees' => [$fees([$onWay85])],
            'create' => [$created(777003)],
            'get' => [new QshprApiException(__('Quickshipper returned HTTP 500 for GET /v1/Order (traceId: -).'))],
        ]);
        $row = $rows()[0];
        check('read-back fails: shipment goes ahead with the Quickshipper id', $error === null
            && $tracks($shipment) === ['shuboqshpr:777003'], (string)$error?->getMessage() . implode(',', $tracks($shipment)));
        check('read-back fails: row from the create answer', $row['state'] === 'dispatched' && (int)$row['qshpr_order_id'] === 777003
            && $row['tracking_url'] === 'https://test.quickshipper.ge/track/SQ777003' && (int)$row['charged_amount'] === 660);

        // Extra delivery: the order already has a shipped delivery (partial shipment).
        // The booking above stands for it once its shipment is saved.
        $connection->update($table, ['shipment_id' => 999999999], ['order_id = ?' => $orderId]);
        [$order, $shipment, $error] = $ship([
            'fees' => [$fees([$onWay85])],
            'create' => [$created(777004)],
            'get' => [$details(777004, 6.6)],
        ]);
        $all = $rows();
        check('extra delivery: booked, new row, customer paid 0', $error === null && count($all) === 2
            && $all[1]['state'] === 'dispatched' && (int)$all[1]['paid_amount'] === 0 && $all[1]['qshpr_order_no'] === 'SQ777004');
        check('extra delivery: same drop-off point as the first', (float)$all[1]['dropoff_latitude'] === 41.709);
        check('extra delivery: admin warned, order comment says so',
            ($messages->added[1][0] ?? '') === 'warning' && str_contains($messages->added[1][1] ?? '', 'extra delivery')
            && str_contains($comments($order), 'Extra Quickshipper delivery'), json_encode($messages->added));

        // The first delivery is already delivered: the next shipment is still an extra delivery.
        $resetRows(quoted: false);
        $earlier = $log->recordBooking($orderId, (string)$order->getIncrementId(), 1, '85', 41.709, 44.776, 660,
            new Booking(25, 'OnWay', 777006, 'SQ777006', null, 660, 60, 660, false), DeliveryLog::STATE_DELIVERED);
        $connection->update($table, ['shipment_id' => 999999999], ['entity_id = ?' => $earlier]);
        [, , $error] = $ship(['fees' => [$fees([$onWay85])], 'create' => [$created(777007)],
            'get' => [$details(777007, 6.6)]]);
        $all = $rows();
        check('extra delivery after a delivered one: customer paid 0, admin warned', $error === null
            && count($all) === 2 && (int)$all[1]['paid_amount'] === 0
            && str_contains($messages->added[1][1] ?? '', 'extra delivery'), json_encode($messages->added));

        // Order placed before deliveries were recorded: geocoded now, paid = order shipping.
        $resetRows(quoted: false);
        $geocoder->calls = 0;
        [$order, , $error] = $ship([
            'fees' => [$fees([$onWay85])],
            'create' => [$created(777005)],
            'get' => [$details(777005, 6.6)],
        ]);
        $row = $rows()[0] ?? [];
        check('no record: address geocoded at shipping, not an extra delivery', $error === null && $geocoder->calls === 1
            && (float)$row['dropoff_latitude'] === 41.701
            && (int)$row['paid_amount'] === (int)round((float)$order->getBaseShippingAmount() * 100)
            && !str_contains($comments($order), 'Extra'), (string)$error?->getMessage());

        // --- Draft mode: the Quickshipper dashboard assigns the courier "after confirmation" ---
        $draft = static fn (int $id): array => ['orderStatus' => 'Draft'] + $created($id);
        $status = static fn (int $id, string $status): array => ['success' => true, 'order' => ['id' => $id,
            'status' => $status, 'orderNo' => "SQ$id"]];
        $confirmed = ['success' => true, 'orderStatus' => 'ReadyForPickup', 'deliveryFee' => 6.6];
        $refusal = static fn (): QshprApiException => new QshprApiException(
            __('Quickshipper error 400 for POST /v1/Order/confirm: Provider rejected the order'),
            apiErrorCode: 400
        );
        $reuseMessage = static fn (int $id): array => ['success', 'This shipment uses the Quickshipper booking of the '
            . "earlier attempt that was not saved: OnWay, order SQ$id. No new courier was booked."];

        $resetRows();
        [$order, $shipment, $error] = $ship([
            'fees' => [$fees([$onWay85])],
            'create' => [$draft(777010)],
            'get' => [$details(777010, 6.6)],
            'confirm' => [$confirmed],
        ]);
        check('draft: confirmed at once, shipment goes ahead', $error === null
            && $operations() === 'fees,create,get,confirm' && ($client->calls[3][1] ?? null) === 777010,
            (string)$error?->getMessage() . ' ' . $operations());
        check('draft: row dispatched once confirmed, tracking number set', $rows()[0]['state'] === 'dispatched'
            && (int)$rows()[0]['qshpr_order_id'] === 777010 && $tracks($shipment) === ['shuboqshpr:SQ777010']);
        check('draft: status from the confirm answer kept (its webhook arrives while the order is locked)',
            $rows()[0]['qshpr_status'] === 'ReadyForPickup' && $rows()[0]['status_changed_at'] !== null,
            (string)$rows()[0]['qshpr_status']);
        check('draft: usual success message', $messages->added === [
            ['success', 'Quickshipper booked OnWay for this shipment: order SQ777010.'],
        ], json_encode($messages->added));

        // Draft booked for a delivery time: confirm drops the time (seen live) → the merchant is told.
        $resetRows();
        $chosenTime = gmdate('Y-m-d\\TH:i:s\\Z', time() + 86400);
        $connection->update($table, ['scheduled_time' => $chosenTime], ['order_id = ?' => $orderId]);
        $customPrice = ['id' => 'custom', 'amount' => 6.6, 'currencyName' => 'GEL', 'deliverySpeedId' => null];
        $readBack = static fn (string $time): array => ['success' => true, 'order' => ['id' => 777040, 'status' => 'ReadyForPickup',
            'orderNo' => 'SQ777040', 'scheduledTime' => $time]];
        [$order, , $error] = $ship([
            'fees' => [$fees([$customPrice])],
            'create' => [$draft(777040)],
            'get' => [$details(777040, 6.6), $readBack(gmdate('Y-m-d\\TH:i:s\\Z', time() + 3300))],
            'confirm' => [$confirmed],
        ]);
        check('time + Draft: confirm dropped the time → order note for the merchant', $error === null
            && $operations() === 'fees,create,get,confirm,get'
            && str_contains($comments($order), 'dropped the delivery time')
            && preg_match('/\(\w{3}, \d{1,2} \w{3}, \d\d:\d\d–\d\d:\d\d, store time\)/u', $comments($order)) === 1,
            (string)$error?->getMessage() . ' ' . $operations());
        $connection->delete($table, ['order_id = ?' => $orderId]);
        $resetRows();
        $connection->update($table, ['scheduled_time' => $chosenTime], ['order_id = ?' => $orderId]);
        [$order, , $error] = $ship([
            'fees' => [$fees([$customPrice])],
            'create' => [$draft(777041)],
            'get' => [$details(777041, 6.6), $readBack($chosenTime)],
            'confirm' => [$confirmed],
        ]);
        check('time + Draft: time kept → no note', $error === null && !str_contains($comments($order), 'dropped the delivery time'));
        $connection->update($table, ['scheduled_time' => null], ['order_id = ?' => $orderId]);

        // Confirm refused: the Draft is kept, and the retry confirms the same Draft.
        $resetRows();
        [, $shipment, $error] = $ship([
            'fees' => [$fees([$onWay85])],
            'create' => [$draft(777011)],
            'get' => [$details(777011, 6.6)],
            'confirm' => [$refusal()],
        ]);
        check('confirm refused: shipment stopped, booking kept, retry without charge', $error instanceof DispatchException
            && str_contains($error->getMessage(), 'did not confirm booking SQ777011')
            && str_contains($error->getMessage(), 'Provider rejected')
            && str_contains($error->getMessage(), 'without a new booking or charge'), (string)$error?->getMessage());
        check('confirm refused: row drafted with the booking, no track', count($rows()) === 1
            && $rows()[0]['state'] === 'drafted' && (int)$rows()[0]['qshpr_order_id'] === 777011
            && $rows()[0]['shipment_id'] === null && $tracks($shipment) === []);
        check('confirm refused: order lock released', !$lock->isLocked($orderId));
        [$order, $shipment, $error] = $ship(['get' => [$status(777011, 'Draft')], 'confirm' => [$confirmed]]);
        check('confirm retry: the same Draft confirmed, no price check, no new booking', $error === null
            && $operations() === 'get,confirm' && $client->calls[0][1] === 777011 && $client->calls[1][1] === 777011,
            (string)$error?->getMessage() . ' ' . $operations());
        check('confirm retry: row dispatched, still one row, tracking number', count($rows()) === 1
            && $rows()[0]['state'] === 'dispatched' && $tracks($shipment) === ['shuboqshpr:SQ777011']
            && (int)$shipment->getData(Dispatcher::SHIPMENT_DELIVERY_ID) === (int)$rows()[0]['entity_id']);
        check('confirm retry: admin told nothing new was booked', $messages->added === [$reuseMessage(777011)],
            json_encode($messages->added));
        check('confirm retry: order comment says the earlier booking is used', str_contains($comments($order),
            'Quickshipper booking from an earlier shipment attempt that was not saved is used for this shipment: '
            . 'OnWay, order SQ777011, 6.60 GEL. Nothing new was booked.'), $comments($order));

        // Draft confirmed in the Quickshipper dashboard meanwhile: no confirm call.
        $resetRows();
        $ship(['fees' => [$fees([$onWay85])], 'create' => [$draft(777016)], 'get' => [$details(777016, 6.6)],
            'confirm' => [$refusal()]]);
        [, , $error] = $ship(['get' => [$status(777016, 'ReadyForPickup')]]);
        check('confirmed elsewhere: reused without confirming, row dispatched', $error === null
            && $operations() === 'get' && $rows()[0]['state'] === 'dispatched', (string)$error?->getMessage());

        // The confirm went through but its record cannot be written: the shipment
        // stops with a clear message, and submitting again reuses the confirmed Draft.
        $resetRows();
        $brokenLog = new class ($resource) extends DeliveryLog {
            public function markConfirmed(int $entityId): bool
            {
                throw new \RuntimeException('database gone');
            }
        };
        $client->queue = ['fees' => [$fees([$onWay85])], 'create' => [$draft(777018)], 'get' => [$details(777018, 6.6)],
            'confirm' => [$confirmed]];
        $client->calls = [];
        [, $shipment] = $newShipment();
        try {
            $om->create(Dispatcher::class, ['deliveryLog' => $brokenLog])->dispatch($shipment);
            $error = null;
        } catch (\Throwable $e) {
            $error = $e;
        }
        $lock->release($orderId);
        check('confirmed, record not updated: shipment stopped with a retryable, clear error',
            $error instanceof DispatchException && $error->isRetryable()
            && str_contains($error->getMessage(), 'Quickshipper confirmed booking SQ777018, but Magento could not record it')
            && $tracks($shipment) === [] && $rows()[0]['state'] === 'drafted', (string)$error?->getMessage());
        [, $shipment, $error] = $ship(['get' => [$status(777018, 'ReadyForPickup')]]);
        check('confirmed, record not updated: submitting again reuses the booking, row dispatched', $error === null
            && $operations() === 'get' && count($rows()) === 1 && $rows()[0]['state'] === 'dispatched'
            && $tracks($shipment) === ['shuboqshpr:SQ777018'], (string)$error?->getMessage() . ' ' . $operations());

        // The courier company refuses at confirm: Quickshipper cancels the order.
        $resetRows();
        [, , $error] = $ship(['fees' => [$fees([$onWay85])], 'create' => [$draft(777015)],
            'get' => [$details(777015, 6.6)], 'confirm' => [['success' => true, 'orderStatus' => 'Cancelled']]]);
        check('cancelled at confirm: shipment stopped, row abandoned', $error instanceof DispatchException
            && str_contains($error->getMessage(), 'did not accept it') && $rows()[0]['state'] === 'abandoned',
            (string)$error?->getMessage());

        // --- Magento's save fails after Quickshipper accepted: the retry reuses the booking ---
        $resetRows();
        [, , $error] = $ship(['fees' => [$fees([$onWay85])], 'create' => [$created(777012)],
            'get' => [$details(777012, 6.6)]]);
        check('save failed: booking left without a shipment', $error === null && count($rows()) === 1
            && $rows()[0]['state'] === 'dispatched' && $rows()[0]['shipment_id'] === null);
        [$order, $shipment, $error] = $ship(['get' => [$status(777012, 'ReadyForPickup')]]);
        check('save failed, retry: booking reused once Quickshipper says it is active, nothing new booked',
            $error === null && $operations() === 'get' && count($rows()) === 1
            && $tracks($shipment) === ['shuboqshpr:SQ777012'] && $messages->added === [$reuseMessage(777012)],
            (string)$error?->getMessage() . ' ' . $operations() . json_encode($messages->added));

        // The unshipped booking was cancelled at Quickshipper: abandoned, booked anew, same paid amount.
        [, $shipment, $error] = $ship([
            'get' => [$status(777012, 'Cancelled'), $details(777013, 6.6)],
            'fees' => [$fees([$onWay85])],
            'create' => [$created(777013)],
        ]);
        $all = $rows();
        check('cancelled at Quickshipper: old row abandoned, new booking', $error === null
            && $operations() === 'get,fees,create,get' && count($all) === 2 && $all[0]['state'] === 'abandoned'
            && $all[1]['state'] === 'dispatched' && (int)$all[1]['qshpr_order_id'] === 777013,
            (string)$error?->getMessage() . ' ' . $operations());
        check('cancelled at Quickshipper: the new booking is the paid delivery, not an extra one',
            (int)$all[1]['paid_amount'] === 660 && $messages->added === [
                ['success', 'Quickshipper booked OnWay for this shipment: order SQ777013.'],
            ], json_encode($messages->added));

        // Quickshipper cannot say whether the unshipped booking is active: stop, book nothing.
        [, , $error] = $ship(['get' => [new QshprApiException(__('Quickshipper request GET /v1/Order failed: timeout'))]]);
        check('status unknown: shipment stopped, nothing booked', $error instanceof DispatchException
            && str_contains($error->getMessage(), 'did not say whether it is still active')
            && $operations() === 'get' && count($rows()) === 2 && $rows()[1]['state'] === 'dispatched',
            (string)$error?->getMessage());

        // Quickshipper does not know the booking (e.g. booked in the other environment).
        [, , $error] = $ship([
            'get' => [
                new QshprApiException(__('Quickshipper error 404 for GET /v1/Order: Order not found'), apiErrorCode: 404),
                $details(777014, 6.6),
            ],
            'fees' => [$fees([$onWay85])],
            'create' => [$created(777014)],
        ]);
        $all = $rows();
        check('unknown to Quickshipper: abandoned, booked anew', $error === null && count($all) === 3
            && $all[1]['state'] === 'abandoned' && (int)$all[2]['qshpr_order_id'] === 777014,
            (string)$error?->getMessage());

        // --- One booking at a time per order ---
        $resetRows();
        [, $shipment, $error] = $ship(['fees' => [$fees([$onWay85])], 'create' => [$created(777017)],
            'get' => [$details(777017, 6.6)]], keepLock: true);
        check('lock: held after booking, until the shipment is committed', $error === null
            && $lock->isLocked($orderId) && (int)$shipment->getData(Dispatcher::SHIPMENT_LOCKED_ORDER) === $orderId);
        $om->create(ReleaseDeliveryLock::class)->execute(new Observer(['event' => new Event(['shipment' => $shipment])]));
        check('lock: released when the shipment commits', !$lock->isLocked($orderId));
        $busyLock = new class () extends DeliveryLock {
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
        $client->queue = ['fees' => [], 'create' => [], 'get' => [], 'confirm' => [], 'cancel' => []];
        $client->calls = [];
        [, $shipment] = $newShipment();
        try {
            $om->create(Dispatcher::class, ['lock' => $busyLock])->dispatch($shipment);
            $error = null;
        } catch (\Throwable $e) {
            $error = $e;
        }
        check('lock: another booking of the order in progress → stopped, no Quickshipper call',
            $error instanceof DispatchException && str_contains($error->getMessage(), 'booking its courier right now')
            && $client->calls === [], (string)$error?->getMessage());

        // A Submit Shipment that waited for the lock while another shipment of
        // the order was saved (e.g. automatic booking): its order is stale.
        $resetRows();
        $client->queue = ['fees' => [], 'create' => [], 'get' => [], 'confirm' => [], 'cancel' => []];
        $client->calls = [];
        [, $shipment] = $newShipment();
        $itemId = 0;
        foreach ($shipment->getAllItems() as $item) {
            if ($item->getOrderItem() !== null && !$item->getOrderItem()->isDummy(true)) {
                $itemId = (int)$item->getOrderItemId();
                break;
            }
        }
        $itemTable = $resource->getTableName('sales_order_item');
        $connection->update($itemTable, ['qty_shipped' => new \Zend_Db_Expr('qty_shipped + 1')], ['item_id = ?' => $itemId]);
        try {
            $shipment->register();
            $error = null;
        } catch (\Throwable $e) {
            $error = $e;
        }
        $connection->update($itemTable, ['qty_shipped' => new \Zend_Db_Expr('qty_shipped - 1')], ['item_id = ?' => $itemId]);
        check('shipped meanwhile: stopped, nothing asked or booked, lock released', $error instanceof DispatchException
            && str_contains($error->getMessage(), 'shipped while this shipment was waiting') && $client->calls === []
            && $rows()[0]['state'] === 'quoted' && !$lock->isLocked($orderId), (string)$error?->getMessage());

        // Admin notifications, recorded.
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

        // --- Clean-up cron ---
        // Other orders' bookings are taken out of this run (rolled back with the rest).
        $connection->update($table, ['alerted_at' => new \Zend_Db_Expr('NOW()')],
            ['order_id != ?' => $orderId, 'alerted_at IS NULL']);
        $resetRows(quoted: false);
        $incrementId = (string)$om->create(Order::class)->load($orderId)->getIncrementId();
        $book = static function (int $id, string $state, int $minutesAgo) use ($log, $connection, $table, $orderId, $incrementId): int {
            $rowId = $log->recordBooking($orderId, $incrementId, 1, '85', 41.709, 44.776, 660,
                new Booking(25, 'OnWay', $id, "SQ$id", null, 660, 60, 660, false), $state);
            $connection->update($table, ['updated_at' => new \Zend_Db_Expr("NOW() - INTERVAL $minutesAgo MINUTE")],
                ['entity_id = ?' => $rowId]);

            return $rowId;
        };
        $row = static fn (int $id): array => $log->find($id) ?? [];
        $young = $book(777020, 'dispatched', 5);
        $ready = $book(777021, 'dispatched', 20);
        $gone = $book(777022, 'dispatched', 20);
        $stuck = $book(777023, 'drafted', 20);
        $assigned = $book(777024, 'dispatched', 20);
        $refused = $book(777025, 'dispatched', 20);
        $flaky = $book(777026, 'dispatched', 20);
        $lost = $book(777027, 'dispatched', 20);
        $unanswered = $book(777028, 'dispatched', 20);
        $client->calls = [];
        $client->queue = [
            'get' => [
                $status(777021, 'ReadyForPickup'),
                $status(777022, 'Cancelled'),
                $status(777023, 'Draft'),
                $status(777024, 'Assigned'),
                $status(777025, 'WaitingForCourier'),
                new QshprApiException(__('Quickshipper request GET /v1/Order failed: timeout')),
                new QshprApiException(__('Quickshipper error 404 for GET /v1/Order: Order not found'), apiErrorCode: 404),
                $status(777028, 'ReadyForPickup'),
            ],
            'cancel' => [
                ['success' => true],
                new QshprApiException(__('Quickshipper error 400 for POST /v1/Order/status: Order status change failed'),
                    apiErrorCode: 400),
                new QshprApiException(__('Quickshipper request POST /v1/Order/status failed: timeout')),
            ],
        ];
        $notices->critical = [];
        $om->create(CleanUpDeliveries::class, ['notifier' => $notices])->execute();
        check('cron: the young booking is left alone, every due one is asked about',
            $operations() === 'get,cancel,get,get,get,get,cancel,get,get,get,cancel' && $row($young)['state'] === 'dispatched',
            $operations());
        check('cron: cancel without an answer → nothing recorded, nobody told, retried next run',
            $row($unanswered)['state'] === 'dispatched' && $row($unanswered)['alerted_at'] === null);
        check('cron: each booking needing the merchant → one admin notification naming the order',
            count($notices->critical) === 3
            && $notices->critical[0][0] === "Quickshipper booking for order #$incrementId needs you"
            && str_contains($notices->critical[0][1], 'SQ777023 is a Draft'), json_encode($notices->critical));
        check('cron: before a courier → cancelled with a reason, abandoned', $client->calls[1][1] === [777021,
            CleanUpDeliveries::CANCEL_COMMENT] && $row($ready)['state'] === 'abandoned');
        check('cron: cancelled at Quickshipper → abandoned', $row($gone)['state'] === 'abandoned');
        check('cron: unknown to Quickshipper (404) → abandoned', $row($lost)['state'] === 'abandoned');
        check('cron: Draft, courier assigned, refused cancel → kept and reported', $row($stuck)['state'] === 'drafted'
            && $row($stuck)['alerted_at'] !== null && $row($assigned)['state'] === 'dispatched'
            && $row($assigned)['alerted_at'] !== null && $row($refused)['state'] === 'dispatched'
            && $row($refused)['alerted_at'] !== null);
        check('cron: Quickshipper not answering → unchanged, retried next run', $row($flaky)['state'] === 'dispatched'
            && $row($flaky)['alerted_at'] === null);
        $history = implode(' | ', $connection->fetchCol($connection->select()
            ->from($resource->getTableName('sales_order_status_history'), ['comment'])
            ->where('parent_id = ?', $orderId)));
        check('cron: order comments for the merchant', str_contains($history, 'Quickshipper booking SQ777021 (6.60 GEL) was cancelled')
            && str_contains($history, 'SQ777022 was never shipped in Magento and is Cancelled')
            && str_contains($history, 'SQ777023 is a Draft that was never confirmed')
            && str_contains($history, 'SQ777024 has no shipment in Magento, but Quickshipper reports it as Assigned')
            && str_contains($history, 'SQ777025 has no shipment in Magento and could not be cancelled')
            && str_contains($history, 'SQ777027 was never shipped in Magento and is not found at Quickshipper'), $history);
        $client->calls = [];
        $client->queue = ['get' => [$status(777026, 'ReadyForPickup'), $status(777028, 'Cancelled')],
            'cancel' => [['success' => true]]];
        $om->create(CleanUpDeliveries::class)->execute();
        check('cron, next run: reported bookings are not asked again, the failed ones are retried',
            $operations() === 'get,cancel,get' && $client->calls[0][1] === 777026 && $row($flaky)['state'] === 'abandoned'
            && $row($unanswered)['state'] === 'abandoned', $operations());
        $client->calls = [];
        $connection->update($table, ['alerted_at' => null, 'state' => 'dispatched'], ['entity_id = ?' => $stuck]);
        $connection->update($table, ['updated_at' => new \Zend_Db_Expr('NOW() - INTERVAL 20 MINUTE')], ['entity_id = ?' => $stuck]);
        $om->create(CleanUpDeliveries::class, ['lock' => $busyLock])->execute();
        check('cron: an order a shipment is booking right now is skipped', $client->calls === []);

        // --- Automatic booking once paid ---
        $sender = new class () extends ShipmentSender {
            public array $sent = [];

            public function __construct()
            {
            }

            public function send(\Magento\Sales\Model\Order\Shipment $shipment, $forceSyncMode = false)
            {
                $this->sent[] = (int)$shipment->getId();

                return true;
            }
        };
        $offConfig = $om->create(Config::class, ['scopeConfig' => overrideScope([P . 'auto_dispatch' => '0'] + STORE, $realScope)]);
        // A fresh order repository each run: the shared one caches orders this check changes in the database.
        $autoShipper = static fn (array $args = []): AutoShipper => $om->create(AutoShipper::class, $args + [
            'orderRepository' => $om->create(OrderRepository::class),
            'shipmentSender' => $sender,
            'notifier' => $notices,
        ]);
        $autoRun = static function (array $queue, array $shipperArgs = [], array $cronArgs = []) use (
            $om,
            $client,
            $notices,
            $autoShipper,
        ): void {
            $client->queue = $queue + ['fees' => [], 'create' => [], 'get' => [], 'confirm' => [], 'cancel' => []];
            $client->calls = [];
            $notices->critical = [];
            $om->create(AutoDispatch::class, $cronArgs + ['autoShipper' => $autoShipper($shipperArgs)])->execute();
        };
        $makeDue = static function (int $attempts = 0) use ($connection, $table, $orderId): void {
            $connection->update($table, ['dispatch_due_at' => new \Zend_Db_Expr('NOW() - INTERVAL 1 MINUTE'),
                'dispatch_attempts' => $attempts], ['order_id = ?' => $orderId, 'state = ?' => 'quoted']);
        };
        $shipments = static fn (): int => (int)$connection->fetchOne($connection->select()
            ->from($resource->getTableName('sales_shipment'), ['COUNT(*)'])->where('order_id = ?', $orderId));
        $history = static fn (): string => implode(' | ', $connection->fetchCol($connection->select()
            ->from($resource->getTableName('sales_order_status_history'), ['comment'])->where('parent_id = ?', $orderId)));
        // Other orders' due deliveries are taken out of these runs, and the
        // order counts as paid in full (both rolled back with the rest).
        $connection->update($table, ['dispatch_due_at' => null], ['order_id != ?' => $orderId]);
        $connection->update($orderTable, ['base_total_paid' => new \Zend_Db_Expr('base_grand_total')],
            ['entity_id = ?' => $orderId]);

        // Paid → due.
        $resetRows();
        $paid = $om->create(Order::class)->load($orderId);
        $trigger = $om->get(AutoDispatchTrigger::class);
        $paid->setBaseTotalPaid(0);
        check('auto: unpaid order not due', !$trigger->markIfPaid($paid) && $rows()[0]['dispatch_due_at'] === null);
        $paid->setBaseTotalPaid((float)$paid->getBaseGrandTotal());
        check('auto: paid in full → due', $trigger->markIfPaid($paid) && $rows()[0]['dispatch_due_at'] !== null);
        check('auto: marked once', !$trigger->markIfPaid($paid));
        $resetRows();
        check('auto: setting off → not due', !$om->create(AutoDispatchTrigger::class, ['config' => $offConfig])
            ->markIfPaid($paid) && $rows()[0]['dispatch_due_at'] === null);
        $om->create(MarkPaidForDispatch::class)->execute(new Observer(['event' => new Event([
            'invoice' => $om->create(Invoice::class)->setOrder($paid),
        ])]));
        check('auto: invoice paid later → due (observer)', $rows()[0]['dispatch_due_at'] !== null);
        $resetRows(quoted: false);
        $om->create(RecordDelivery::class)->execute(new Observer(['event' => new Event(['order' => $paid])]));
        check('auto: card paid at checkout → due as soon as the delivery is recorded',
            ($rows()[0]['state'] ?? '') === 'quoted' && $rows()[0]['dispatch_due_at'] !== null);

        // A refusal: no shipment, not retried, the merchant is told.
        $resetRows();
        $makeDue();
        $before = $shipments();
        $autoRun(['fees' => [$fees([$onWay85])], 'create' => [new QshprApiException(
            __('Quickshipper error 400 for POST /v1/Order: Not enough balance'), apiErrorCode: 400)]]);
        $row = $rows()[0];
        check('auto, refused: booked nothing, shipped nothing', $operations() === 'fees,create' && $shipments() === $before
            && $row['state'] === 'quoted', $operations());
        check('auto, refused: not retried, reason kept', (int)$row['dispatch_attempts'] === 1
            && $row['dispatch_due_at'] === null && str_contains((string)$row['last_error'], 'Not enough balance'));
        $incrementId = (string)$om->create(Order::class)->load($orderId)->getIncrementId();
        check('auto, refused: admin notification and order comment', count($notices->critical) === 1
            && $notices->critical[0][0] === "Quickshipper did not book a courier for order #$incrementId"
            && str_contains($history(), 'Quickshipper could not book the courier automatically'),
            json_encode($notices->critical));

        // Quickshipper not answering: tried again after 10 min, stops after the 3rd attempt.
        $resetRows();
        $makeDue();
        $timeout = static fn (): QshprApiException => new QshprApiException(__('Operation timed out after 10001 milliseconds'));
        $autoRun(['fees' => [$timeout()]]);
        $row = $rows()[0];
        $minutesAhead = (int)$connection->fetchOne('SELECT TIMESTAMPDIFF(MINUTE, NOW(), ?)', [$row['dispatch_due_at']]);
        check('auto, no answer: due again in about 10 minutes, nobody alerted', (int)$row['dispatch_attempts'] === 1
            && $minutesAhead >= 9 && $minutesAhead <= 10 && $notices->critical === [], "in $minutesAhead min");
        $autoRun([]);
        check('auto, no answer: not tried before it is due', $client->calls === []);
        $makeDue(attempts: 2);
        $autoRun(['fees' => [$timeout()]]);
        $row = $rows()[0];
        check('auto, no answer: third failure stops and alerts', (int)$row['dispatch_attempts'] === 3
            && $row['dispatch_due_at'] === null && count($notices->critical) === 1);

        // The booking itself got no answer: it may exist, so never retried.
        $resetRows();
        $makeDue();
        $autoRun(['fees' => [$fees([$onWay85])], 'create' => [$timeout()]]);
        $row = $rows()[0];
        check('auto, booking unanswered: not retried, merchant told to check the dashboard',
            $row['dispatch_due_at'] === null && count($notices->critical) === 1
            && str_contains($notices->critical[0][1], 'Check the Quickshipper dashboard'), json_encode($notices->critical));

        // Nothing to do: order being shipped by the admin (lock), setting switched off, order on hold.
        $resetRows();
        $makeDue();
        $autoRun([], cronArgs: ['lock' => $busyLock]);
        check('auto: order busy → skipped, still due', $client->calls === [] && $rows()[0]['dispatch_due_at'] !== null);
        $autoRun([], shipperArgs: ['config' => $offConfig]);
        check('auto: setting switched off → no longer due, nothing booked', $client->calls === []
            && $rows()[0]['dispatch_due_at'] === null);
        $makeDue();
        $orderTableRow = $connection->fetchRow($connection->select()->from($orderTable, ['state', 'status'])
            ->where('entity_id = ?', $orderId));
        $connection->update($orderTable, ['state' => Order::STATE_HOLDED, 'status' => 'holded'], ['entity_id = ?' => $orderId]);
        $autoRun([]);
        check('auto: order on hold → no longer due, nothing booked', $client->calls === []
            && $rows()[0]['dispatch_due_at'] === null);
        $connection->update($orderTable, $orderTableRow, ['entity_id = ?' => $orderId]);
        $makeDue();
        $connection->update($orderTable, ['base_total_paid' => 0], ['entity_id = ?' => $orderId]);
        $autoRun([]);
        check('auto: no longer paid in full (e.g. the paid invoice was never saved) → no longer due, nothing booked',
            $client->calls === [] && $rows()[0]['dispatch_due_at'] === null);
        $connection->update($orderTable, ['base_total_paid' => new \Zend_Db_Expr('base_grand_total')],
            ['entity_id = ?' => $orderId]);

        // An unexpected error (here: the order cannot be loaded): stopped and reported, not retried every minute.
        $missingOrders = new class () extends OrderRepository {
            public function __construct()
            {
            }

            public function get($id)
            {
                throw new NoSuchEntityException(__('The entity that was requested doesn\'t exist.'));
            }
        };
        $resetRows();
        $makeDue();
        $autoRun([], shipperArgs: ['orderRepository' => $missingOrders]);
        $row = $rows()[0];
        check('auto, unexpected error: no longer due, reason kept, merchant told', $row['dispatch_due_at'] === null
            && (int)$row['dispatch_attempts'] === 1 && str_contains((string)$row['last_error'], 'requested doesn')
            && count($notices->critical) === 1 && str_contains($history(), 'could not book the courier automatically'),
            json_encode($row) . json_encode($notices->critical));

        // Booked and shipped. A savepoint undoes the shipment so the checks below still have items to ship.
        $resetRows();
        $makeDue();
        $before = $shipments();
        $sender->sent = [];
        $connection->query('SAVEPOINT qshpr_auto_dispatch');
        $autoRun(['fees' => [$fees([$onWay85])], 'create' => [$created(777030)], 'get' => [$details(777030, 6.6)]]);
        $row = $rows()[0];
        $shipmentId = (int)$connection->fetchOne($connection->select()->from($resource->getTableName('sales_shipment'),
            ['MAX(entity_id)'])->where('order_id = ?', $orderId));
        $trackNo = (string)$connection->fetchOne($connection->select()
            ->from($resource->getTableName('sales_shipment_track'), ['track_number'])->where('parent_id = ?', $shipmentId));
        check('auto: booked and shipped, one shipment with the tracking number', $operations() === 'fees,create,get'
            && $shipments() === $before + 1 && $trackNo === 'SQ777030', $operations() . " $trackNo");
        check('auto: delivery row dispatched and linked to the shipment', $row['state'] === 'dispatched'
            && (int)$row['shipment_id'] === $shipmentId, json_encode($row));
        check('auto: shipment email sent, order comment saved', $sender->sent === [$shipmentId]
            && str_contains($history(), 'Courier booked with Quickshipper: OnWay, order SQ777030'), json_encode($sender->sent));
        check('auto: nothing due any more, order lock released', $om->get(DeliveryLog::class)->findDispatchDue(10) === []
            && !$lock->isLocked($orderId));
        $connection->query('ROLLBACK TO SAVEPOINT qshpr_auto_dispatch');
        check('auto: savepoint undone, order unshipped again', $shipments() === $before);

        // The normal case, saved.
        $quotedId = $resetRows();
        [$order, $shipment, $error] = $ship([
            'fees' => [$fees([$onWay85], [$wolt])],
            'create' => [$created(777001)],
            'get' => [$details(777001, 6.6)],
        ]);
        check('normal: booked without error', $error === null, (string)$error?->getMessage());
        check('normal: fresh price, create, read back', $operations() === 'fees,create,get', $operations());
        $query = $client->calls[0][1];
        check('normal: price check for the quoted point and the shipment weight', $query['ToLatitude'] === 41.709
            && $query['ToLongitude'] === 44.776 && ($query['CartWeight'] ?? null) === round(0.998 * (float)$order->getTotalQtyOrdered(), 3),
            json_encode($query));
        $sent = $client->calls[1][1];
        check('normal: booking sends the chosen courier and price', $sent['provider'] === ['providerId' => 25,
            'providerFeeId' => '85', 'deliverySpeedId' => 85]);
        check('normal: booking sends the Magento order number', $sent['integrationOrderId'] === $order->getIncrementId());
        check('normal: drop-off phone as the customer entered it', $sent['dropOffInfo']['phone']
            === trim((string)$order->getShippingAddress()->getTelephone()));
        check('normal: tracking number = Quickshipper order number', $tracks($shipment) === ['shuboqshpr:SQ777001'],
            implode(',', $tracks($shipment)));
        check('normal: order comment with courier, number and prices', str_contains($comments($order),
            'Courier booked with Quickshipper: OnWay, order SQ777001, 6.60 GEL (the customer paid 6.60 GEL).'), $comments($order));
        check('normal: success message, no warning', $messages->added === [
            ['success', 'Quickshipper booked OnWay for this shipment: order SQ777001.'],
        ], json_encode($messages->added));
        $row = $rows()[0];
        check('normal: quoted row → dispatched with the booking', (int)$row['entity_id'] === $quotedId
            && $row['state'] === 'dispatched' && (int)$row['qshpr_order_id'] === 777001 && $row['qshpr_order_no'] === 'SQ777001'
            && (int)$row['charged_amount'] === 660 && (int)$row['service_fee'] === 60 && $row['provider_name'] === 'OnWay'
            && $row['shipment_id'] === null, json_encode($row));
        check('normal: status from the create answer kept', $row['qshpr_status'] === 'ReadyForPickup',
            (string)$row['qshpr_status']);

        $om->create(Transaction::class)->addObject($shipment)->addObject($order)->save();
        check('normal: shipment saved', (int)$shipment->getId() > 0);
        check('normal: delivery linked to the saved shipment', (int)$rows()[0]['shipment_id'] === (int)$shipment->getId());

        $carrier = $om->get(CarrierFactory::class)->create('shuboqshpr', (int)$order->getStoreId());
        $info = $carrier->getTrackingInfo('SQ777001');
        check('tracking: available, links to Quickshipper\'s page', $carrier->isTrackingAvailable()
            && $info && $info->getUrl() === 'https://test.quickshipper.ge/track/SQ777001' && $info->getTracking() === 'SQ777001');
        check('tracking: unknown number → no detail', $carrier->getTrackingInfo('SQ000') === false);
    } finally {
        $connection->rollBack();
    }

    check('rolled back: the order\'s delivery rows are as before', $rows() == $rowsBefore);
    $qtyShipped = (float)$connection->fetchOne($connection->select()
        ->from($resource->getTableName('sales_order_item'), ['SUM(qty_shipped)'])->where('order_id = ?', $orderId));
    check('rolled back: order still unshipped', $qtyShipped === 0.0);
}

// --- 3. Log ---
$logText = substr((string)file_get_contents($logFile), $logOffset);
if ($orderId > 0) {
    check('log: booking summary line', str_contains($logText, 'booked OnWay SQ777001 (Quickshipper id 777001), charged 6.60 GEL'));
    check('log: failed read-back logged as a warning', str_contains($logText, 'order 777003 was booked but could not be read back'));
    check('log: Draft confirmed, booking reused', str_contains($logText, 'SQ777010 (Quickshipper id 777010, Draft confirmed)')
        && str_contains($logText, 'reused the unshipped booking OnWay SQ777012 (Quickshipper id 777012)'));
    check('log: refused confirm as a warning', str_contains($logText, 'Draft SQ777011 (id 777011) not confirmed'));
    check('log: confirmed booking whose record failed as CRITICAL',
        str_contains($logText, 'Quickshipper confirmed SQ777018 (id 777018) but the delivery record was not updated'));
    check('log: clean-up cancel, and bookings needing the merchant as CRITICAL',
        str_contains($logText, 'SQ777021 (Quickshipper id 777021) cancelled at Quickshipper; marked abandoned')
        && preg_match('/CRITICAL: clean-up: order \S+: booking SQ777023 .* needs the merchant \(Quickshipper status Draft\)/', $logText) === 1);
    check('log: automatic booking retried, stopped, shipped', str_contains($logText, 'attempt 1 failed, trying again in 10 min')
        && str_contains($logText, 'not booked after 3 attempt(s), merchant notified')
        && str_contains($logText, 'booked SQ777030 and shipped'));
    check('log: no customer phone or name in the log', !str_contains($logText, 'Test Customer')
        && !str_contains($logText, (string)$om->create(Order::class)->load($orderId)->getShippingAddress()?->getTelephone() ?: '@@'));
}

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
