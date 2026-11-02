<?php
// Checks for the Quickshipper API client: Config, TokenCache, QshprClient.
//  1. config.xml defaults and Config getters (no network)
//  2. stubbed HTTP: every response shape Quickshipper uses, token caching, 401 retry
//  3. transport failure via an unreachable host
//  4. LIVE sandbox, read-only: login, token reuse, GET /v1/Account, GET /v1/Order/fees
//  5. log assertions on var/log/shubo_qshpr.log (this run's lines only)
//
// Sandbox credentials for section 4: env QSHPR_USERNAME / QSHPR_PASSWORD, else a
// local, untracked .qshpr.env file in the Magento root. Config is overridden in
// memory only — nothing is written to the database. No orders are created.
// QSHPR_OFFLINE=1 skips the live section: nothing is sent to Quickshipper.
//
// Run inside phpfpm from the Magento root: php <module-dir>/dev/checks/qshpr-client-check.php

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\LogSanitizer;
use Shubo\Qshpr\Model\QshprClient;
use Shubo\Qshpr\Model\TokenCache;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$logFile = BP . '/var/log/shubo_qshpr.log';
$logOffset = is_file($logFile) ? (int)filesize($logFile) : 0;

const P = 'carriers/shuboqshpr/';
const SANDBOX_AUTH = 'https://test-auth.quickshipper.ge';
const SANDBOX_API = 'https://delivery-test.quickshipper.ge';

require __DIR__ . '/_stubs.php';

/** Real Curl clients that also record "METHOD /path" of every request. */
function countingCurlFactory(): CurlFactory
{
    return new class () extends CurlFactory {
        public array $calls = [];

        public function __construct()
        {
        }

        public function create(array $data = [])
        {
            return new class ($this) extends Curl {
                public function __construct(private readonly object $recorder)
                {
                    parent::__construct();
                }

                public function get($uri)
                {
                    $this->recorder->calls[] = 'GET ' . parse_url($uri, PHP_URL_PATH);
                    parent::get($uri);
                }

                public function post($uri, $params)
                {
                    $this->recorder->calls[] = 'POST ' . parse_url($uri, PHP_URL_PATH);
                    parent::post($uri, $params);
                }
            };
        }
    };
}

$encryptor = $om->get(EncryptorInterface::class);
$realScope = $om->get(ScopeConfigInterface::class);

function stubCredentials(EncryptorInterface $encryptor, string $password = 'stub-password-SECRETCHECK'): array
{
    return [
        P . 'username' => 'stub@example.com',
        P . 'password' => $encryptor->encrypt($password),
        P . 'sandbox_mode' => '1',
        P . 'debug' => '0',
    ];
}

/**
 * Build Config → TokenCache → QshprClient over a given Curl factory and cache.
 *
 * @return array{0: QshprClient, 1: TokenCache}
 */
function graph($om, array $overrides, CurlFactory $curlFactory, CacheInterface $cache): array
{
    $config = $om->create(Config::class, [
        'scopeConfig' => overrideScope($overrides, $om->get(ScopeConfigInterface::class)),
    ]);
    $tokenCache = $om->create(TokenCache::class, ['config' => $config, 'cache' => $cache]);
    $client = $om->create(QshprClient::class, [
        'curlFactory' => $curlFactory,
        'tokenCache' => $tokenCache,
        'config' => $config,
    ]);

    return [$client, $tokenCache];
}

/** @return array{0: QshprClient, 1: CurlFactory, 2: CacheInterface} */
function stubClient($om, array $overrides, array $queue, ?CacheInterface $cache = null): array
{
    $factory = fakeCurlFactory($queue);
    $cache ??= memoryCache();
    [$client] = graph($om, $overrides, $factory, $cache);

    return [$client, $factory, $cache];
}

function tokenBody(string $token): array
{
    return [200, json_encode(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 31535999])];
}

function expectApiException(callable $run, string $needle, string $name, ?callable $extra = null): void
{
    try {
        $run();
        check($name, false, 'no exception thrown');
    } catch (QshprApiException $e) {
        $ok = str_contains($e->getMessage(), $needle) && ($extra === null || $extra($e));
        check($name, $ok, $e->getMessage() . ' | apiErrorCode ' . var_export($e->getApiErrorCode(), true));
    }
}

$okAccount = [200, '{"httpStatusCode":200,"userMessage":"Operation Completed","success":true,"errors":[],'
    . '"shopName":"Stub Shop","apiKey":"APIKEY-LEAK-CHECK","balance":"0 ₾"}'];

// --- 1. config.xml defaults and Config getters (no network) ---
check('config.xml: sandbox mode on by default', (string)$realScope->getValue(P . 'sandbox_mode') === '1');
// Read from the module's config.xml: a store that saved the section keeps its own value.
$moduleDefaults = simplexml_load_file(dirname(__DIR__, 2) . '/etc/config.xml')->default->carriers->shuboqshpr;
check('config.xml: api_timeout default 20 (room for the slow first answer)', (string)$moduleDefaults->api_timeout === '20');
check('config.xml: public sandbox OAuth client', (string)$realScope->getValue(P . 'sandbox_client_id') === 'DeliveryApiClient'
    && (string)$realScope->getValue(P . 'sandbox_client_secret') === 'DeliveryApiSecret');

$cfg = fn (array $o): Config => $om->create(Config::class, ['scopeConfig' => overrideScope($o, $realScope)]);
check('config: sandbox hosts', $cfg([P . 'sandbox_mode' => '1'])->getAuthUrl() === SANDBOX_AUTH
    && $cfg([P . 'sandbox_mode' => '1'])->getApiUrl() === SANDBOX_API);
$prod = $cfg([
    P . 'sandbox_mode' => '0',
    P . 'production_client_id' => 'prod-client',
    P . 'production_client_secret' => $encryptor->encrypt('prod-secret'),
]);
check('config: production hosts', $prod->getAuthUrl() === 'https://auth.quickshipper.app'
    && $prod->getApiUrl() === 'https://delivery.quickshipper.app');
check('config: production client from admin fields, secret decrypted',
    $prod->getClientId() === 'prod-client' && $prod->getClientSecret() === 'prod-secret');
check('config: timeout 0 falls back to 20', $cfg([P . 'api_timeout' => '0'])->getApiTimeout() === 20);
check('config: negative timeout falls back to 20', $cfg([P . 'api_timeout' => '-5'])->getApiTimeout() === 20);
check('config: saved timeout used as is', $cfg([P . 'api_timeout' => '10'])->getApiTimeout() === 10);
expectApiException(
    fn () => $cfg([P . 'sandbox_mode' => '0', P . 'production_client_id' => ''])->getClientId(strict: true),
    'client ID is not configured',
    'config: production without a client id -> strict getter throws'
);

// --- 2. Stubbed HTTP ---
[$client, $factory] = stubClient($om, [P . 'username' => ''] + stubCredentials($encryptor), []);
expectApiException(fn () => $client->getAccount(), 'login email is not configured',
    'missing login email -> fails before any HTTP call');
check('missing login email -> zero HTTP calls', $factory->made === []);

[$client, $factory] = stubClient($om, [P . 'password' => ''] + stubCredentials($encryptor), []);
expectApiException(fn () => $client->getAccount(), 'password is not configured',
    'missing password -> fails before any HTTP call');

$cache = memoryCache();
// A timeout of its own: the check must not depend on the store's saved setting.
[$client, $factory] = stubClient($om, [P . 'api_timeout' => '7'] + stubCredentials($encryptor),
    [tokenBody('STUB-TOKEN-1'), $okAccount], $cache);
$account = $client->getAccount();
check('happy path: account returned', ($account['shopName'] ?? null) === 'Stub Shop');
[$login, $api] = $factory->made + [null, null];
check('login: POST to sandbox /connect/token', $login->method === 'POST' && $login->uri === SANDBOX_AUTH . '/connect/token');
check('login: Basic auth with the public sandbox client',
    ($login->sentHeaders()['Authorization'] ?? '') === 'Basic ' . base64_encode('DeliveryApiClient:DeliveryApiSecret'));
check('login: form-encoded body (JSON is rejected by Quickshipper)',
    ($login->sentHeaders()['Content-Type'] ?? '') === 'application/x-www-form-urlencoded');
parse_str($login->body, $form);
check('login: password grant, DeliveryApi scope, credentials',
    $form === [
        'grant_type' => 'password',
        'scope' => 'DeliveryApi',
        'username' => 'stub@example.com',
        'password' => 'stub-password-SECRETCHECK',
    ]);
check('api: GET sandbox /v1/Account with the bearer token',
    $api->method === 'GET' && $api->uri === SANDBOX_API . '/v1/Account'
        && ($api->sentHeaders()['Authorization'] ?? '') === 'Bearer STUB-TOKEN-1');
check('api: timeout from config (7 s)', $api->sentTimeout() === 7 && $login->sentTimeout() === 7);
$key = array_key_first($cache->data);
check('token cache: one entry, value encrypted', count($cache->data) === 1
    && !str_contains((string)$cache->data[$key], 'STUB-TOKEN-1')
    && $encryptor->decrypt((string)$cache->data[$key]) === 'STUB-TOKEN-1');
check('token cache: key does not contain the login or password',
    !str_contains($key, 'stub@example.com') && !str_contains($key, 'SECRETCHECK'));
check('token cache: lifetime capped at 24 h', $cache->lifetimes[$key] === 86400);
check('token cache: tagged CONFIG (flushed with the config cache)', in_array('CONFIG', $cache->tags[$key], true));

[$client, $factory] = stubClient($om, stubCredentials($encryptor), [$okAccount], $cache);
$client->getAccount();
check('token reuse: second call makes no login request', count($factory->made) === 1
    && $factory->made[0]->uri === SANDBOX_API . '/v1/Account');

[$client, $factory] = stubClient($om, stubCredentials($encryptor, 'a-new-password'), [tokenBody('STUB-TOKEN-2'), $okAccount], $cache);
$client->getAccount();
check('changed password -> fresh login (old token not reused)', count($factory->made) === 2
    && ($factory->made[1]->sentHeaders()['Authorization'] ?? '') === 'Bearer STUB-TOKEN-2');

$invalidToken = [401, '{"status":"Error","message":"Invalid Token"}'];
[$client, $factory] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('OLD-TOKEN'), $invalidToken, tokenBody('NEW-TOKEN'), $okAccount,
]);
$account = $client->getAccount();
check('401 -> token dropped, fresh login, one retry succeeds', ($account['shopName'] ?? null) === 'Stub Shop'
    && count($factory->made) === 4
    && ($factory->made[3]->sentHeaders()['Authorization'] ?? '') === 'Bearer NEW-TOKEN');

[$client, $factory] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('T1'), $invalidToken, tokenBody('T2'), $invalidToken,
]);
expectApiException(fn () => $client->getAccount(), 'HTTP 401', '401 twice -> exception after exactly one retry',
    fn (QshprApiException $e) => $e->getApiErrorCode() === null);
check('401 twice -> four HTTP calls, no loop', count($factory->made) === 4);

[$client, $factory] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('T1'), [417, '{"status":"Error","message":"Please Provide authToken"}'],
]);
expectApiException(fn () => $client->getAccount(), 'HTTP 417', '417 -> exception, no retry');
check('417 -> no retry', count($factory->made) === 2);

[$client] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('T1'), [500, '<html>Internal Server Error</html>'],
]);
expectApiException(fn () => $client->getAccount(), 'HTTP 500', 'HTTP 500 -> exception, not definitive',
    fn (QshprApiException $e) => $e->getApiErrorCode() === null);

[$client] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('T1'),
    [400, '{"title":"One or more validation errors occurred.","status":400,'
        . '"errors":{"DropOffInfo":["The DropOffInfo field is required."]},"traceId":"00-TRACEID-CHECK-01"}'],
]);
expectApiException(fn () => $client->getAccount(), '00-TRACEID-CHECK-01',
    '400 validation error -> exception with traceId, definitive 400',
    fn (QshprApiException $e) => $e->getApiErrorCode() === 400);

[$client] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('T1'),
    [200, '{"httpStatusCode":404,"userMessage":"Order not found","developerMessage":"Order not found","success":false}'],
]);
expectApiException(fn () => $client->getAccount(), 'Order not found',
    'HTTP 200 + success:false -> exception carrying body httpStatusCode 404',
    fn (QshprApiException $e) => $e->getApiErrorCode() === 404);

[$client] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('T1'), [200, '{"httpStatusCode":500,"success":false,"errors":{"weird":["shape"]}}'],
]);
expectApiException(fn () => $client->getAccount(), 'unknown error', 'success:false with odd errors shape -> no fatal');

[$client] = stubClient($om, stubCredentials($encryptor), [tokenBody('T1'), [200, 'not-json{{{']]);
expectApiException(fn () => $client->getAccount(), 'unreadable response', 'non-JSON body -> exception');

[$client] = stubClient($om, stubCredentials($encryptor), [tokenBody('T1'), [200, '"just a string"']]);
expectApiException(fn () => $client->getAccount(), 'unreadable response', 'JSON string body -> exception');

[$client] = stubClient($om, stubCredentials($encryptor), [tokenBody('T1'), [200, '{"httpStatusCode":200}']]);
expectApiException(fn () => $client->getAccount(), 'error', 'envelope without success flag -> exception');

[$client, $factory] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('T1'), [200, '{"httpStatusCode":200,"success":true,"errors":[],"fees":[],"distance":0}'],
]);
$fees = $client->getFees(['FromLatitude' => 41.6934, 'FromLongitude' => 44.8015, 'ToCityName' => 'თბილისი']);
check('empty fees list -> normal result, no exception', ($fees['fees'] ?? null) === []);
$feesUri = $factory->made[1]->uri;
check('fees: query string sent on GET /v1/Order/fees',
    str_starts_with($feesUri, SANDBOX_API . '/v1/Order/fees?')
        && str_contains($feesUri, 'FromLatitude=41.6934')
        && str_contains($feesUri, 'ToCityName=' . urlencode('თბილისი')));

[$client, $factory] = stubClient($om, stubCredentials($encryptor), [
    [400, '{"error":"invalid_grant","error_description":"User not found","error_uri":"https://documentation.openiddict.com/errors/ID2024"}'],
]);
expectApiException(fn () => $client->getAccount(), 'invalid_grant', 'login refused -> exception with OAuth error',
    fn (QshprApiException $e) => str_contains($e->getMessage(), 'User not found'));
check('login refused -> API never called', count($factory->made) === 1);

[$client] = stubClient($om, stubCredentials($encryptor), [[200, '{"token_type":"Bearer"}']]);
expectApiException(fn () => $client->getAccount(), 'no token in response', 'login 200 without access_token -> exception');

[$client] = stubClient($om, stubCredentials($encryptor), [[500, '{"access_token":"TOKEN-LEAK-CHECK"}']]);
expectApiException(fn () => $client->getAccount(), 'HTTP 500', 'login HTTP 500 -> exception (body logged masked)');

[$client] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('T1'),
    [200, json_encode(['httpStatusCode' => 400, 'success' => false,
        'userMessage' => "evil\n2026-01-01 shubo_qshpr.INFO: FORGED-LINE"])],
]);
expectApiException(fn () => $client->getAccount(), 'evil', 'log-injection message -> exception (flattened in log)');

[$client] = stubClient($om, [P . 'debug' => '1'] + stubCredentials($encryptor), [tokenBody('T1'), $okAccount]);
$client->getAccount();
check('debug on: stubbed account call completed', true);

// Confirm a Draft and cancel: POST with the JSON bodies of Quickshipper's spec.
[$client, $factory] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('STUB-TOKEN-1'),
    [200, '{"httpStatusCode":200,"success":true,"orderId":42615,"orderStatus":"ReadyForPickup","deliveryFee":4.9}'],
    [200, '{"httpStatusCode":200,"success":true}'],
    [200, '{"httpStatusCode":400,"userMessage":"Order status change failed","success":false}'],
]);
$confirmed = $client->confirmOrder(42615);
$client->cancelOrder(42615, 'Cancelled by Magento: check');
[, $confirm, $cancel] = $factory->made + [null, null, null];
check('confirm: POST /v1/Order/confirm {orderId}, bearer token, JSON', $confirm->method === 'POST'
    && $confirm->uri === SANDBOX_API . '/v1/Order/confirm' && json_decode($confirm->body, true) === ['orderId' => 42615]
    && ($confirm->sentHeaders()['Authorization'] ?? '') === 'Bearer STUB-TOKEN-1'
    && ($confirm->sentHeaders()['Content-Type'] ?? '') === 'application/json', $confirm->body);
check('confirm: answer returned with the new status', ($confirmed['orderStatus'] ?? null) === 'ReadyForPickup');
check('cancel: POST /v1/Order/status {orderId, status Cancelled, orderComment}', $cancel->method === 'POST'
    && $cancel->uri === SANDBOX_API . '/v1/Order/status' && json_decode($cancel->body, true) === [
        'orderId' => 42615, 'status' => 'Cancelled', 'orderComment' => 'Cancelled by Magento: check',
    ], $cancel->body);
try {
    $client->cancelOrder(42615, 'again');
    check('cancel refused (e.g. a Draft): success:false -> exception with code 400', false, 'no exception');
} catch (QshprApiException $e) {
    check('cancel refused (e.g. a Draft): success:false -> exception with code 400', $e->getApiErrorCode() === 400
        && str_contains($e->getMessage(), 'Order status change failed'), $e->getMessage());
}

// Webhooks: list, create, re-point (PUT through Curl's custom-request option).
[$client, $factory] = stubClient($om, stubCredentials($encryptor), [
    tokenBody('STUB-TOKEN-1'),
    [200, '{"httpStatusCode":200,"success":true,"webhooks":[{"id":1081,"callBackUrl":"https://x.test/qshpr/webhook/status?key=a","isActive":true},"junk"]}'],
    [200, '{"httpStatusCode":200,"success":true,"webhookId":1090}'],
    [200, '{"httpStatusCode":200,"success":true}'],
]);
$webhooks = $client->listWebhooks();
$newId = $client->createWebhook('https://shop.test/qshpr/webhook/status?key=k');
$client->updateWebhook(1081, 'https://shop.test/qshpr/webhook/status?key=k');
[, $list, $create, $update] = $factory->made + [null, null, null, null];
check('webhooks: GET /v1/Webhook, entries that are not objects dropped', $list->method === 'GET'
    && $list->uri === SANDBOX_API . '/v1/Webhook' && count($webhooks) === 1 && $webhooks[0]['id'] === 1081);
check('webhooks: create posts callBackUrl, OrderStatusChange, 3 retries; returns the id', $newId === 1090
    && $create->method === 'POST' && json_decode($create->body, true) === [
        'callBackUrl' => 'https://shop.test/qshpr/webhook/status?key=k', 'type' => 'OrderStatusChange', 'maxRetryCount' => 3,
    ], $create->body);
check('webhooks: update is a PUT with callbackUrl (Quickshipper\'s spelling there), switched on',
    ($update->sentOptions()[CURLOPT_CUSTOMREQUEST] ?? null) === 'PUT' && json_decode($update->body, true) === [
        'id' => 1081, 'callbackUrl' => 'https://shop.test/qshpr/webhook/status?key=k', 'type' => 'OrderStatusChange',
        'isActive' => true, 'maxRetryCount' => 3,
    ], $update->body);
check('webhooks: create and list are not PUT', !isset($create->sentOptions()[CURLOPT_CUSTOMREQUEST])
    && !isset($list->sentOptions()[CURLOPT_CUSTOMREQUEST]));

// --- 3. Transport failure (real Curl, unreachable host) ---
[$client] = graph($om, [
    P . 'sandbox_auth_url' => 'https://127.0.0.1:1',
    P . 'sandbox_api_url' => 'https://127.0.0.1:1',
    P . 'api_timeout' => '3',
] + stubCredentials($encryptor), $om->get(CurlFactory::class), memoryCache());
expectApiException(fn () => $client->getAccount(), 'failed', 'unreachable host -> transport QshprApiException');

// --- 3b. Log sanitizer: built-in secrets, extra keys and body limit from di.xml ---
$sanitizer = $om->create(LogSanitizer::class);
$masked = $sanitizer->mask(['password' => 'p', 'nested' => ['apiKey' => 'k', 'customerPhone' => '599'], 'empty' => '']);
check('sanitizer: built-in secrets masked at any depth, other keys kept',
    $masked['password'] === '***' && $masked['nested']['apiKey'] === '***' && $masked['nested']['customerPhone'] === '599');
$sanitizer = $om->create(LogSanitizer::class, ['maskedKeys' => ['phone' => 'customerPhone'], 'bodyLimit' => 40]);
$masked = $sanitizer->mask(['password' => 'p', 'customerphone' => '599']);
check('sanitizer: extra di.xml key masked (any case), built-in secrets still masked',
    $masked['password'] === '***' && $masked['customerphone'] === '***');
$excerpt = $sanitizer->excerpt('{"access_token":"SECRET","customerPhone":"599123456","note":"' . str_repeat('x', 100) . '"}');
check('sanitizer: excerpt masks built-in and extra keys, cut to the di.xml limit',
    !str_contains($excerpt, 'SECRET') && !str_contains($excerpt, '599123456') && mb_strlen($excerpt) === 40, $excerpt);
$excerpt = $om->create(LogSanitizer::class)->excerpt(str_repeat('y', 1500));
check('sanitizer: excerpt cut to 1000 characters by default', mb_strlen($excerpt) === 1000);

// --- 4. LIVE sandbox, read-only ---
[$liveUser, $livePassword] = sandboxCredentials();
if (isOffline()) {
    echo "SKIP  live section: QSHPR_OFFLINE=1\n";
} else {
    check('live: sandbox credentials available (env or .qshpr.env)', $liveUser !== '' && $livePassword !== '');
}

if ($liveUser !== '' && $livePassword !== '') {
    $countingCurl = countingCurlFactory();
    $liveCache = memoryCache();
    [$live, $liveTokenCache] = graph($om, [
        P . 'username' => $liveUser,
        P . 'password' => $encryptor->encrypt($livePassword),
        P . 'sandbox_mode' => '1',
        P . 'debug' => '1',
    ], $countingCurl, $liveCache);

    try {
        $account = $live->getAccount();
        check('live: GET /v1/Account succeeds', ($account['success'] ?? null) === true,
            'shop: ' . (string)($account['shopName'] ?? '?'));
        $liveApiKey = is_string($account['apiKey'] ?? null) ? $account['apiKey'] : '';
        check('live: account carries an apiKey (must be masked in the log)', $liveApiKey !== '');

        $live->getAccount();
        check('live: second call reuses the cached token (one login in total)',
            $countingCurl->calls === ['POST /connect/token', 'GET /v1/Account', 'GET /v1/Account'],
            implode(', ', $countingCurl->calls));
        $liveToken = (string)$liveTokenCache->load(null);

        // Freedom Square → Vake Park, Tbilisi (~5 km); Europe Square, Batumi.
        $tbilisi = $live->getFees([
            'FromStreetName' => 'Freedom Square', 'FromCityName' => 'Tbilisi',
            'FromLatitude' => 41.6934, 'FromLongitude' => 44.8015,
            'ToStreetName' => 'Ilia Chavchavadze Avenue', 'ToCityName' => 'Tbilisi',
            'ToLatitude' => 41.7102, 'ToLongitude' => 44.7453,
        ]);
        $gelPrices = [];
        foreach ((array)($tbilisi['fees'] ?? []) as $fee) {
            foreach ((array)($fee['prices'] ?? []) as $price) {
                if (($price['currencyName'] ?? null) === 'GEL' && (float)($price['amount'] ?? 0) > 0) {
                    $gelPrices[] = ($fee['providerName'] ?? '?') . ' ' . $price['amount'];
                }
            }
        }
        check('live: fees Tbilisi -> Tbilisi returns courier prices in GEL', $gelPrices !== [],
            implode(', ', $gelPrices) . '; distance ' . ($tbilisi['distance'] ?? '?') . ' km');

        $intercity = $live->getFees([
            'FromStreetName' => 'Freedom Square', 'FromCityName' => 'Tbilisi',
            'FromLatitude' => 41.6934, 'FromLongitude' => 44.8015,
            'ToStreetName' => 'Europe Square', 'ToCityName' => 'Batumi',
            'ToLatitude' => 41.6509, 'ToLongitude' => 41.6360,
        ]);
        check('live: fees Tbilisi -> Batumi = empty list, no exception', ($intercity['fees'] ?? null) === []);
    } catch (QshprApiException $e) {
        check('live: sandbox calls succeed', false, $e->getMessage());
    }
}

// --- 5. Log assertions (this run's lines only) ---
$log = substr((string)file_get_contents($logFile), $logOffset);
check('log: login summary line', str_contains($log, 'POST /connect/token login succeeded in'));
check('log: call summary line', str_contains($log, 'GET /v1/Account succeeded in'));
check('log: 401 retry warning', str_contains($log, 'token rejected, logging in again and retrying once'));
check('log: validation error with traceId', str_contains($log, 'traceId: 00-TRACEID-CHECK-01'));
check('log: business failure with httpStatusCode', str_contains($log, 'httpStatusCode 404 "Order not found"'));
check('log: OAuth failure line', str_contains($log, 'error invalid_grant "User not found"'));
check('log: transport failure line', str_contains($log, 'transport failure after'));
check('log: debug response dump with apiKey masked', str_contains($log, '"apiKey":"***"'));
check('log: stub apiKey never logged', !str_contains($log, 'APIKEY-LEAK-CHECK'));
check('log: token in an error body masked', !str_contains($log, 'TOKEN-LEAK-CHECK'));
check('log: stub password never logged', !str_contains($log, 'SECRETCHECK'));
check('log: stub tokens never logged', !str_contains($log, 'STUB-TOKEN-1') && !str_contains($log, 'NEW-TOKEN'));
check('log: injected newline flattened', !preg_match('/^2026-01-01 shubo_qshpr\.INFO/m', $log)
    && str_contains($log, 'evil 2026-01-01'));
if (isset($liveToken, $livePassword)) {
    check('log: live token never logged', !str_contains($log, $liveToken));
    check('log: live password never logged', !str_contains($log, $livePassword));
    check('log: live apiKey never logged', ($liveApiKey ?? '') === '' || !str_contains($log, $liveApiKey));
}

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
