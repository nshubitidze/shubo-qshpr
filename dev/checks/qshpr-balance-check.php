<?php
// Checks for the Quickshipper balance in the admin header (Account\BalanceReader,
// ViewModel\AdminBalance, Controller\Adminhtml\Account\Balance):
//  1. BalanceReader over a STUB client: read, cached 5 min per login, failure
//     cached 1 min, no login → no call, odd answers
//  2. AdminBalance: shown only when switched on, a login is saved and the admin
//     may create shipments; test-environment flag; endpoint URL
//  3. LIVE (skipped with QSHPR_OFFLINE=1): one GET /v1/Account on the sandbox —
//     read-only, nothing is created. Login: env QSHPR_USERNAME / QSHPR_PASSWORD,
//     else the local, untracked .qshpr.env in the Magento root
//
// Run inside phpfpm from the Magento root: php <module-dir>/dev/checks/qshpr-balance-check.php

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Account\BalanceReader;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\QshprClient;
use Shubo\Qshpr\ViewModel\AdminBalance;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

require __DIR__ . '/_stubs.php';

const P = 'carriers/shuboqshpr/';

/** Client stand-in: getAccount() answers from a queue and counts calls. */
function stubClient(array $answers): QshprClient
{
    return new class ($answers) extends QshprClient {
        public int $calls = 0;

        public function __construct(private array $answers)
        {
        }

        public function getAccount(?int $storeId = null): array
        {
            $this->calls++;
            $answer = array_shift($this->answers) ?? throw new \RuntimeException('unexpected getAccount() call');
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        }
    };
}

function configWith($om, array $overrides): Config
{
    return $om->create(Config::class, [
        'scopeConfig' => overrideScope($overrides, $om->get(ScopeConfigInterface::class)),
    ]);
}

function reader($om, QshprClient $client, $cache, array $overrides = []): BalanceReader
{
    return $om->create(BalanceReader::class, [
        'client' => $client,
        'cache' => $cache,
        'config' => configWith($om, $overrides + [P . 'username' => 'shop@example.com']),
    ]);
}

// --- 1. BalanceReader ---
$cache = memoryCache();
$client = stubClient([['success' => true, 'shopName' => 'Stub', 'balance' => '6.80 ₾']]);
$reader = reader($om, $client, $cache);
check('reader: balance as Quickshipper words it', $reader->getBalance() === '6.80 ₾');
$key = array_key_first($cache->data);
check('reader: cached for 5 minutes, tagged', $cache->lifetimes[$key] === 300
    && in_array('SHUBO_QSHPR_BALANCE', $cache->tags[$key], true));
check('reader: cache key holds no login', !str_contains($key, 'shop@example.com'));
check('reader: second read from the cache (no call)', $reader->getBalance() === '6.80 ₾' && $client->calls === 1);

$cache2 = memoryCache();
$other = stubClient([['success' => true, 'balance' => '1 ₾']]);
check('reader: another login has its own cache entry',
    reader($om, $other, $cache2, [P . 'username' => 'other@example.com'])->getBalance() === '1 ₾'
        && array_key_first($cache2->data) !== $key);

$cache = memoryCache();
$client = stubClient([new QshprApiException(__('Quickshipper returned HTTP 503 for GET /v1/Account (traceId: -).')),
    ['success' => true, 'balance' => '2 ₾']]);
$reader = reader($om, $client, $cache);
check('reader: Quickshipper failure → null', $reader->getBalance() === null);
$key = array_key_first($cache->data);
check('reader: failure remembered for 1 minute', $cache->data[$key] === 'none' && $cache->lifetimes[$key] === 60);
check('reader: within that minute no new call', $reader->getBalance() === null && $client->calls === 1);
$cache->remove($key);
check('reader: after it, asked again', $reader->getBalance() === '2 ₾' && $client->calls === 2);

$client = stubClient([]);
check('reader: no login saved → null, no call',
    reader($om, $client, memoryCache(), [P . 'username' => ' '])->getBalance() === null && $client->calls === 0);
check('reader: answer without balance → null',
    reader($om, stubClient([['success' => true]]), memoryCache())->getBalance() === null);
check('reader: empty balance → null',
    reader($om, stubClient([['success' => true, 'balance' => '  ']]), memoryCache())->getBalance() === null);

// --- 2. AdminBalance ---
function viewModel($om, array $overrides, bool $allowed): AdminBalance
{
    $authorization = new class ($allowed) implements AuthorizationInterface {
        public array $asked = [];

        public function __construct(private readonly bool $allowed)
        {
        }

        public function isAllowed($resource, $privilege = null)
        {
            $this->asked[] = $resource;

            return $this->allowed;
        }
    };

    return $om->create(AdminBalance::class, [
        'config' => configWith($om, $overrides),
        'authorization' => $authorization,
        'balanceReader' => reader($om, stubClient([['success' => true, 'balance' => '6.80 ₾']]), memoryCache()),
    ]);
}

$on = [P . 'show_balance' => '1', P . 'username' => 'shop@example.com'];
check('header: shown when on, login saved and allowed', viewModel($om, $on, true)->isVisible());
check('header: hidden by default (config.xml)', !viewModel($om, [P . 'username' => 'shop@example.com'], true)->isVisible());
check('header: hidden when switched off', !viewModel($om, [P . 'show_balance' => '0'] + $on, true)->isVisible());
check('header: hidden without a login', !viewModel($om, [P . 'username' => ''] + $on, true)->isVisible());
check('header: hidden for admins who may not create shipments', !viewModel($om, $on, false)->isVisible());
check('header: test-environment flag follows Sandbox Mode',
    viewModel($om, [P . 'sandbox_mode' => '1'] + $on, true)->isSandbox()
        && !viewModel($om, [P . 'sandbox_mode' => '0'] + $on, true)->isSandbox());
check('header: balance text from the reader', viewModel($om, $on, true)->getBalance() === '6.80 ₾');
check('header: fetches from the admin balance route',
    str_contains(viewModel($om, $on, true)->getBalanceUrl(), '/shuboqshpr/account/balance/'));

// --- 3. LIVE ---
[$username, $password] = sandboxCredentials();
if ($username === '' || $password === '') {
    echo 'SKIP  live sandbox balance (' . (isOffline() ? 'QSHPR_OFFLINE=1' : 'no sandbox login') . ")\n";
} else {
    $encryptor = $om->get(\Magento\Framework\Encryption\EncryptorInterface::class);
    $config = configWith($om, [
        P . 'sandbox_mode' => '1',
        P . 'username' => $username,
        P . 'password' => $encryptor->encrypt($password),
    ]);
    $live = $om->create(BalanceReader::class, [
        'config' => $config,
        'client' => $om->create(QshprClient::class, ['config' => $config]),
        'cache' => memoryCache(),
    ]);
    $balance = $live->getBalance();
    check('live: sandbox balance read', is_string($balance) && preg_match('/\d/', $balance) === 1, (string)$balance);
}

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
