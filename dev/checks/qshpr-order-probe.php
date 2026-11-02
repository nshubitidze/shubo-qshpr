<?php
/**
 * Dev probe: creates ONE real order in the Quickshipper SANDBOX for one
 * courier, reads it back, fetches its waybill, confirms it if it is a Draft,
 * cancels it and prints every raw answer. Used to learn what the API docs
 * leave out (initial status, charging, phone format, waybill format,
 * cancellation). Refuses to run unless the store is in Sandbox Mode.
 *
 * Creating an order costs money even in the sandbox: run it only when that is agreed.
 *
 * Run inside the phpfpm container from the Magento root:
 *   php <module-dir>/dev/checks/qshpr-order-probe.php <providerId> [phonePrefix]
 * e.g. 25 (OnWay), 2 (Wolt), 12 (Glovo). phonePrefix defaults to +995.
 *
 * Env: PROBE_CONFIRM=0 leaves a Draft unconfirmed; PROBE_WAIT=<seconds> reads
 * the order again after that pause, before cancelling; PROBE_AUTO_ASSIGN=1|0
 * sends the order field autoAssign (left out when unset); PROBE_DROP=delete
 * removes the order with DELETE /v1/Order instead of cancelling it.
 *
 * Cancel or confirm one existing sandbox order (e.g. one booked from the
 * admin Ship button; confirm sends a Draft to the courier):
 *   php <module-dir>/dev/checks/qshpr-order-probe.php cancel <Quickshipper order id>
 *   php <module-dir>/dev/checks/qshpr-order-probe.php confirm <Quickshipper order id>
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\HTTP\Client\CurlFactory;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\QshprClient;
use Shubo\Qshpr\Model\TokenCache;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$action = in_array($argv[1] ?? '', ['cancel', 'confirm'], true) ? $argv[1] : null;
$providerId = $action !== null ? 0 : (int)($argv[1] ?? 0);
$phonePrefix = (string)($argv[2] ?? '+995');
if ($action !== null ? (int)($argv[2] ?? 0) <= 0 : !in_array($providerId, [2, 12, 25], true)) {
    fwrite(STDERR, "usage: qshpr-order-probe.php <25|2|12> [phonePrefix] | cancel <orderId> | confirm <orderId>\n");
    exit(2);
}

$config = $om->get(Config::class);
if (!$config->isSandbox() || !str_contains($config->getApiUrl(), 'delivery-test.')) {
    fwrite(STDERR, "refusing: not in Sandbox Mode (API URL " . $config->getApiUrl() . ")\n");
    exit(2);
}
$api = $config->getApiUrl();

// Test trip: Freedom Square → Nikoladze St, Tbilisi. Dummy names and phones.
const PICKUP = ['lat' => 41.6934, 'lng' => 44.8015, 'address' => 'Freedom Square 1', 'city' => 'Tbilisi'];
// Close enough to the pickup for Wolt's lowest distance price (5.50 GEL, 2026-10-01).
const DROPOFF = ['lat' => 41.7010, 'lng' => 44.7960, 'address' => 'Iakob Nikoladze St 7', 'city' => 'Tbilisi'];
const WEIGHT_KG = 1.5;
const DUMMY_PHONE = '500000001';

$client = $om->get(QshprClient::class);
$account = $client->getAccount(); // logs in and caches the token when needed
$token = $om->get(TokenCache::class)->load(null);

/**
 * One raw call; prints and returns [status, decoded body].
 */
$call = static function (string $method, string $path, ?array $body = null) use ($om, $api, $token): array {
    $curl = $om->get(CurlFactory::class)->create();
    $curl->setHeaders([
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ]);
    $curl->setTimeout(30);
    $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
    $started = microtime(true);
    if ($method === 'GET') {
        $curl->get($api . $path);
    } elseif ($method === 'POST') {
        $curl->post($api . $path, $json);
    } else {
        $curl->setOption(CURLOPT_CUSTOMREQUEST, $method);
        $curl->post($api . $path, $json);
    }
    $ms = (int)round((microtime(true) - $started) * 1000);
    $raw = (string)$curl->getBody();
    $decoded = json_decode($raw, true);
    $shown = is_array($decoded) && isset($decoded['base64'])
        ? array_merge($decoded, ['base64' => '<' . strlen((string)$decoded['base64']) . ' chars>'])
        : ($decoded ?? $raw);
    echo "\n### $method $path -> HTTP {$curl->getStatus()} in {$ms}ms\n";
    if ($body !== null) {
        echo 'request: ' . $json . "\n";
    }
    echo 'response: ' . (is_string($shown) ? substr($shown, 0, 2000) : json_encode($shown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";

    return [(int)$curl->getStatus(), is_array($decoded) ? $decoded : []];
};

echo "balance before: " . ($account['balance'] ?? '?') . "\n";

if ($action !== null) {
    $targetId = (int)$argv[2];
    $call('GET', '/v1/Order?OrderId=' . $targetId);
    if ($action === 'cancel') {
        $call('POST', '/v1/Order/status', ['orderId' => $targetId, 'status' => 'Cancelled', 'orderComment' => 'Sandbox test order']);
    } else {
        $call('POST', '/v1/Order/confirm', ['orderId' => $targetId]);
    }
    $call('GET', '/v1/Order?OrderId=' . $targetId);
    echo "balance after $action: " . ($client->getAccount()['balance'] ?? '?') . "\n";
    exit(0);
}
$call('GET', '/v1/Provider/list');

// Fresh quote for the courier.
$fees = $client->getFees([
    'FromStreetName' => PICKUP['address'], 'FromCityName' => PICKUP['city'],
    'FromLatitude' => PICKUP['lat'], 'FromLongitude' => PICKUP['lng'],
    'ToStreetName' => DROPOFF['address'], 'ToCityName' => DROPOFF['city'],
    'ToLatitude' => DROPOFF['lat'], 'ToLongitude' => DROPOFF['lng'],
    'CartWeight' => WEIGHT_KG,
]);
$courier = null;
foreach ($fees['fees'] ?? [] as $fee) {
    if ((int)$fee['providerId'] === $providerId) {
        $courier = $fee;
    }
}
if ($courier === null || empty($courier['prices'])) {
    fwrite(STDERR, "courier $providerId not offered for the test trip; nothing created\n");
    exit(1);
}
$price = $courier['prices'][0];
echo "quote: {$courier['providerName']} {$price['amount']} GEL, price id {$price['id']}, fee {$courier['serviceFee']}\n";
// A create the balance cannot cover still leaves a cancelled order behind; don't send it.
$balance = (float)preg_replace('/[^0-9.]/', '', (string)($account['balance'] ?? '0'));
if ((float)$price['amount'] + (float)$courier['serviceFee'] > $balance) {
    fwrite(STDERR, "balance $balance GEL does not cover price + fee; nothing sent\n");
    exit(1);
}

$provider = ['providerId' => $providerId, 'providerFeeId' => (string)$price['id']];
if ($price['deliverySpeedId'] !== null) {
    $provider['deliverySpeedId'] = (int)$price['deliverySpeedId'];
}
// Weight-priced couriers: the weight bracket, as GET /v1/Order/weights names it
// (parcelDimensionsId; for OnWay the same number as the price id).
if (!empty($courier['hasWeight']) && is_numeric($price['id'])) {
    $provider['parcelDimensionId'] = (int)$price['id'];
}
if ($providerId === 2) {
    $provider['preparationTime'] = 30;
}

$reference = 'probe-' . time();
[, $created] = $call('POST', '/v1/Order', [
    'pickUpInfo' => [
        'address' => PICKUP['address'], 'latitude' => PICKUP['lat'], 'longitude' => PICKUP['lng'],
        'name' => 'Test Shop', 'phonePrefix' => $phonePrefix, 'phone' => DUMMY_PHONE,
        'city' => PICKUP['city'], 'country' => 'Georgia',
    ],
    'dropOffInfo' => [
        'address' => DROPOFF['address'], 'latitude' => DROPOFF['lat'], 'longitude' => DROPOFF['lng'],
        'name' => 'Test Customer', 'phonePrefix' => $phonePrefix, 'phone' => DUMMY_PHONE,
        'city' => DROPOFF['city'], 'country' => 'Georgia', 'addressComment' => 'Sandbox test order',
    ],
    'provider' => $provider,
    'comment' => 'Sandbox test order, will be cancelled',
    'integrationOrderId' => $reference,
    'cartAmount' => 20,
    'cartWeight' => WEIGHT_KG,
] + (in_array(getenv('PROBE_AUTO_ASSIGN'), ['0', '1'], true)
    ? ['autoAssign' => getenv('PROBE_AUTO_ASSIGN') === '1']
    : []));

$orderId = (int)($created['orderId'] ?? 0);
if (($created['success'] ?? false) !== true || $orderId === 0) {
    fwrite(STDERR, "order not created\n");
    exit(1);
}
echo "\nCREATED order $orderId, status on create: " . ($created['orderStatus'] ?? '?') . ", reference $reference\n";

[, $order] = $call('GET', '/v1/Order?OrderId=' . $orderId);
echo "balance after create: " . ($client->getAccount()['balance'] ?? '?') . "\n";

[, $waybill] = $call('GET', '/v1/Order/waybill?OrderId=' . $orderId);
if (isset($waybill['base64'])) {
    $bytes = (string)base64_decode((string)$waybill['base64'], true);
    echo 'waybill: ' . strlen($bytes) . ' bytes, starts with ' . json_encode(substr($bytes, 0, 8)) . "\n";
}

$wait = (int)getenv('PROBE_WAIT');
if ($wait > 0) {
    echo "\nwaiting {$wait}s …\n";
    sleep($wait);
    [, $order] = $call('GET', '/v1/Order?OrderId=' . $orderId);
    echo "balance after wait: " . ($client->getAccount()['balance'] ?? '?') . "\n";
}

$status = (string)($order['order']['status'] ?? $created['orderStatus'] ?? '');
if (strcasecmp($status, 'Draft') === 0 && getenv('PROBE_CONFIRM') !== '0') {
    $call('POST', '/v1/Order/confirm', ['orderId' => $orderId]);
    $call('GET', '/v1/Order?OrderId=' . $orderId);
    echo "balance after confirm: " . ($client->getAccount()['balance'] ?? '?') . "\n";
}

$cancelled = [];
if (getenv('PROBE_DROP') !== 'delete') {
    [, $cancelled] = $call('POST', '/v1/Order/status', [
        'orderId' => $orderId,
        'status' => 'Cancelled',
        'orderComment' => 'Sandbox test order',
    ]);
}
if (($cancelled['success'] ?? false) !== true) {
    $call('DELETE', '/v1/Order', ['orderId' => $orderId]);
}
$call('GET', '/v1/Order?OrderId=' . $orderId);
echo "balance at end: " . ($client->getAccount()['balance'] ?? '?') . "\n";

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$call('GET', '/v1/Order/list?FromDate=' . $today . '&Limit=5&Page=1');
