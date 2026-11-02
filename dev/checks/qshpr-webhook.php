<?php
/**
 * Dev tool: list, register or switch webhooks on/off on the Quickshipper
 * SANDBOX account and print the raw answers. Quickshipper has no delete
 * for webhooks: one that is no longer wanted is switched off.
 * Refuses to run unless the store is in Sandbox Mode.
 *
 * Registering a webhook changes the vendor account (webhooks cannot be
 * deleted, only switched off): run it only when that is agreed.
 *
 * Run inside the phpfpm container from the Magento root:
 *   php <module-dir>/dev/checks/qshpr-webhook.php list
 *   php <module-dir>/dev/checks/qshpr-webhook.php add <url> [OrderStatusChange|CourierLocation] [maxRetryCount]
 *   php <module-dir>/dev/checks/qshpr-webhook.php off <webhook id>
 *   php <module-dir>/dev/checks/qshpr-webhook.php on <webhook id> [new url]
 *   php <module-dir>/dev/checks/qshpr-webhook.php relay <webhook id> <public base URL>
 *
 * relay re-points a webhook at <public base URL>/qshpr/webhook/status?key=…
 * with this store's webhook key (created as "Connect status updates" does when
 * missing): for a store that Quickshipper cannot reach, behind the webhook
 * watch in forward mode. The key is masked in the printed output.
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\HTTP\Client\CurlFactory;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\QshprClient;
use Shubo\Qshpr\Model\Status\WebhookRegistrar;
use Shubo\Qshpr\Model\TokenCache;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

const TYPES = ['OrderStatusChange', 'CourierLocation'];
$action = (string)($argv[1] ?? '');
$usage = "usage: qshpr-webhook.php list | add <url> [type] [maxRetryCount] | off <id> | on <id> [url]"
    . " | relay <id> <public base URL>\n";

if (!in_array($action, ['list', 'add', 'off', 'on', 'relay'], true)
    || ($action === 'add' && !preg_match('#^https://#', (string)($argv[2] ?? '')))
    || ($action === 'add' && !in_array($argv[3] ?? TYPES[0], TYPES, true))
    || (in_array($action, ['off', 'on', 'relay'], true) && (int)($argv[2] ?? 0) <= 0)
    || ($action === 'relay' && !preg_match('#^https://#', (string)($argv[3] ?? '')))
) {
    fwrite(STDERR, $usage);
    exit(2);
}

$config = $om->get(Config::class);
if (!$config->isSandbox() || !str_contains($config->getApiUrl(), 'delivery-test.')) {
    fwrite(STDERR, "refusing: not in Sandbox Mode (API URL " . $config->getApiUrl() . ")\n");
    exit(2);
}
$api = $config->getApiUrl();

$secret = null;
if ($action === 'relay') {
    // The same key "Connect status updates" creates and the webhook controller checks.
    $registrar = $om->get(WebhookRegistrar::class);
    $secret = (new \ReflectionMethod($registrar, 'key'))->invoke($registrar);
    $argv[3] = rtrim((string)$argv[3], '/') . '/' . WebhookRegistrar::WEBHOOK_PATH . '?key=' . rawurlencode($secret);
    $action = 'on';
}

$om->get(QshprClient::class)->getAccount(); // logs in and caches the token when needed
$token = $om->get(TokenCache::class)->load(null);

/**
 * One raw call; prints and returns the decoded body.
 */
$call = static function (string $method, ?array $body = null) use ($om, $api, $token, &$secret): array {
    $mask = static fn (string $text): string => $secret === null ? $text
        : str_replace([$secret, rawurlencode($secret)], '***', $text);
    $curl = $om->get(CurlFactory::class)->create();
    $curl->setHeaders([
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ]);
    $curl->setTimeout(30);
    $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($method === 'GET') {
        $curl->get($api . '/v1/Webhook');
    } elseif ($method === 'POST') {
        $curl->post($api . '/v1/Webhook', $json);
    } else {
        $curl->setOption(CURLOPT_CUSTOMREQUEST, $method);
        $curl->post($api . '/v1/Webhook', $json);
    }
    echo "### $method /v1/Webhook -> HTTP {$curl->getStatus()}\n";
    if ($body !== null) {
        echo 'request: ' . $mask($json) . "\n";
    }
    echo 'response: ' . $mask((string)$curl->getBody()) . "\n\n";
    $decoded = json_decode((string)$curl->getBody(), true);

    return is_array($decoded) ? $decoded : [];
};

if ($action === 'add') {
    $call('POST', [
        'callBackUrl' => (string)$argv[2],
        'type' => (string)($argv[3] ?? TYPES[0]),
        'maxRetryCount' => (int)($argv[4] ?? 3),
    ]);
} elseif ($action !== 'list') {
    $id = (int)$argv[2];
    $current = null;
    foreach ($call('GET')['webhooks'] ?? [] as $webhook) {
        if ((int)($webhook['id'] ?? 0) === $id) {
            $current = $webhook;
        }
    }
    if ($current === null) {
        fwrite(STDERR, "webhook $id not found on this account\n");
        exit(1);
    }
    $call('PUT', [
        'id' => $id,
        'isActive' => $action === 'on',
        'type' => $current['type'] ?? null,
        'maxRetryCount' => $current['maxRetryCount'] ?? null,
        'callbackUrl' => $action === 'on' && isset($argv[3]) ? (string)$argv[3] : ($current['callBackUrl'] ?? null),
    ]);
}

$call('GET');
