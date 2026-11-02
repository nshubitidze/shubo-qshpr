<?php
/**
 * Dev watcher: follows one Quickshipper order and prints a line whenever
 * something changes — status, courier, price, status log — to see how an
 * order moves (e.g. whether the sandbox moves orders at all). Read-only: it
 * only calls GET /v1/Order and GET /v1/Account.
 *
 * Run inside the phpfpm container from the Magento root:
 *   php <module-dir>/dev/checks/qshpr-order-watch.php [Quickshipper order id] [minutes=60] [seconds between reads=60]
 * Without an order id it follows the most recently booked delivery in
 * shubo_qshpr_delivery. Stops early once the order is Delivered, Cancelled
 * or DeliveryFailed.
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\QshprClient;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

const FINAL_STATUSES = ['Delivered', 'Cancelled', 'DeliveryFailed'];

$orderId = (int)($argv[1] ?? 0);
$minutes = max(1, (int)($argv[2] ?? 60));
$interval = max(15, (int)($argv[3] ?? 60));

if ($orderId === 0) {
    $resource = $om->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $orderId = (int)$connection->fetchOne($connection->select()
        ->from($resource->getTableName(DeliveryLog::TABLE), ['qshpr_order_id'])
        ->where('qshpr_order_id IS NOT NULL')
        ->order('entity_id DESC')->limit(1));
}

if ($orderId === 0) {
    fwrite(STDERR, "no Quickshipper order to watch: pass an order id, or book one first\n");
    exit(2);
}

$client = $om->get(QshprClient::class);
$stamp = static fn (): string => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tbilisi')))->format('H:i:s');

try {
    echo $stamp() . " watching Quickshipper order $orderId for $minutes min, every {$interval}s; balance "
        . ($client->getAccount()['balance'] ?? '?') . "\n";
} catch (QshprApiException $e) {
    echo $stamp() . ' balance read failed: ' . $e->getMessage() . "\n";
}

$last = null;
$deadline = time() + $minutes * 60;

while (true) {
    try {
        $answer = $client->getOrder($orderId);
        $order = (array)($answer['order'] ?? []);
        $courier = (array)($answer['courier'] ?? []);
        $state = [
            'status' => $order['status'] ?? null,
            'orderNo' => $order['orderNo'] ?? null,
            'price' => $order['orderPrice'] ?? null,
            'serviceFee' => $order['serviceFee'] ?? null,
            'courier' => $courier === [] ? null : [
                'name' => $courier['name'] ?? null,
                'status' => $courier['status'] ?? null,
                'transport' => $courier['transportType'] ?? null,
            ],
            'deliveryStart' => $order['deliveryStartDate'] ?? null,
            'approxMin' => $order['approximateDeliveryMin'] ?? null,
            'statusLog' => array_map(
                static fn ($l): string => ($l['status'] ?? '?') . '@' . ($l['createDate'] ?? '?'),
                (array)($order['statusLogs'] ?? [])
            ),
            'comments' => array_map(
                static fn ($c): string => (string)($c['comment'] ?? ''),
                (array)($order['orderComments'] ?? [])
            ),
        ];

        if ($state !== $last) {
            echo $stamp() . ' ' . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $last = $state;
        }

        if (in_array($state['status'], FINAL_STATUSES, true)) {
            echo $stamp() . " final status {$state['status']}; balance "
                . ($client->getAccount()['balance'] ?? '?') . "\n";
            break;
        }
    } catch (QshprApiException $e) {
        echo $stamp() . ' read failed: ' . $e->getMessage() . "\n";
    }

    if (time() + $interval > $deadline) {
        echo $stamp() . " stopped after $minutes min; balance " . ($client->getAccount()['balance'] ?? '?') . "\n";
        break;
    }

    sleep($interval);
}
