<?php
/**
 * Shared helpers for the check suites: the PASS/FAIL counter and in-memory
 * stand-ins for Curl, the cache and the scope config.
 *
 * The stand-ins are ANONYMOUS classes on purpose, always written `new class (`:
 * setup:di:compile require_once's every module file whose first class
 * declaration has a name (Magento\Setup\Module\Di\Code\Reader\ClassesScanner),
 * so a named class here would make every compile RUN the suites.
 *
 * Usage (after bootstrapping Magento): require __DIR__ . '/_stubs.php';
 */

declare(strict_types=1);

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}


/** Curl stub: records the request, returns a canned status/body, no network I/O. */
function fakeCurl(int $status, string $body): Curl
{
    return new class ($status, $body) extends Curl {
        public string $method = '';
        public string $uri = '';
        public string $body = '';

        public function __construct(private readonly int $fakeStatus, private readonly string $fakeBody)
        {
        }

        public function get($uri)
        {
            $this->method = 'GET';
            $this->uri = $uri;
        }

        public function post($uri, $params)
        {
            $this->method = 'POST';
            $this->uri = $uri;
            $this->body = (string)$params;
        }

        public function getStatus(): int
        {
            return $this->fakeStatus;
        }

        public function getBody(): string
        {
            return $this->fakeBody;
        }

        public function sentHeaders(): array
        {
            return $this->_headers;
        }

        public function sentTimeout(): int
        {
            return (int)$this->_timeout;
        }

        public function sentOptions(): array
        {
            return $this->_curlUserOptions;
        }
    };
}

/** Hands out queued fake Curls and remembers them; an unexpected extra call fails loudly. */
function fakeCurlFactory(array $queue): CurlFactory
{
    return new class ($queue) extends CurlFactory {
        public array $made = [];

        public function __construct(private array $queue)
        {
        }

        public function create(array $data = [])
        {
            if ($this->queue === []) {
                throw new \RuntimeException('unexpected HTTP call');
            }
            [$status, $body] = array_shift($this->queue);

            return $this->made[] = fakeCurl($status, $body);
        }
    };
}

/** In-memory cache that records what was saved and for how long. */
function memoryCache(): CacheInterface
{
    return new class () implements CacheInterface {
        public array $data = [];
        public array $lifetimes = [];
        public array $tags = [];

        public function getFrontend()
        {
            throw new \RuntimeException('not used');
        }

        public function load($identifier)
        {
            return $this->data[$identifier] ?? false;
        }

        public function save($data, $identifier, $tags = [], $lifeTime = null)
        {
            $this->data[$identifier] = $data;
            $this->lifetimes[$identifier] = $lifeTime;
            $this->tags[$identifier] = $tags;

            return true;
        }

        public function remove($identifier)
        {
            unset($this->data[$identifier]);

            return true;
        }

        public function clean($tags = [])
        {
            $this->data = [];

            return true;
        }
    };
}

/** Real scope config with per-path overrides. */
function overrideScope(array $overrides, ScopeConfigInterface $real): ScopeConfigInterface
{
    return new class ($overrides, $real) implements ScopeConfigInterface {
        public function __construct(private readonly array $overrides, private readonly ScopeConfigInterface $real)
        {
        }

        public function getValue($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null)
        {
            return array_key_exists($path, $this->overrides)
                ? $this->overrides[$path]
                : $this->real->getValue($path, $scopeType, $scopeCode);
        }

        public function isSetFlag($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null)
        {
            return (bool)$this->getValue($path, $scopeType, $scopeCode);
        }
    };
}

/**
 * Value of a shell-style assignment, as `source` would read it: the env file
 * is shell syntax, so e.g. `abc\!` means "abc!" (a backslash escapes the
 * next character outside single quotes).
 */
function shellValue(string $raw): string
{
    if (strlen($raw) >= 2 && $raw[0] === "'" && str_ends_with($raw, "'")) {
        return substr($raw, 1, -1);
    }
    if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
        return (string)preg_replace('/\\\\([\\\\"$`])/', '$1', substr($raw, 1, -1));
    }

    return (string)preg_replace('/\\\\(.)/', '$1', $raw);
}

function sandboxCredentials(): array
{
    if (isOffline()) {
        return ['', ''];
    }

    $username = (string)getenv('QSHPR_USERNAME');
    $password = (string)getenv('QSHPR_PASSWORD');
    $file = BP . '/.qshpr.env';

    if (($username === '' || $password === '') && is_readable($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*(?:export\s+)?(QSHPR_USERNAME|QSHPR_PASSWORD)\s*=\s*(.*?)\s*$/', $line, $m)) {
                $value = shellValue($m[2]);
                $m[1] === 'QSHPR_USERNAME' ? $username = $value : $password = $value;
            }
        }
    }

    return [$username, $password];
}

/** QSHPR_OFFLINE=1: skip every live section, so a run sends nothing to Quickshipper. */
function isOffline(): bool
{
    return getenv('QSHPR_OFFLINE') === '1';
}
