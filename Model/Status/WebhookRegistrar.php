<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Status;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\QshprClient;

/**
 * Connects a store to Quickshipper's status webhook: makes sure the
 * installation has a webhook key, then registers the store's webhook URL on
 * the Quickshipper account the store logs in with — or re-points the
 * webhook registered for the same address before, since Quickshipper
 * cannot delete webhooks.
 *
 * The key goes to Quickshipper twice: as the webhook's apiKey, which
 * Quickshipper sends back in an "apikey" header, and in the URL. The URL
 * copy stays until the header is seen working in production; webhooks
 * connected before the header existed keep working with it.
 */
class WebhookRegistrar
{
    /** Path of the webhook endpoint, relative to the store's base URL. */
    public const string WEBHOOK_PATH = 'qshpr/webhook/status';

    /** connect() registered a new webhook. */
    public const string ACTION_CREATED = 'created';

    /** connect() re-pointed the webhook registered for this address before. */
    public const string ACTION_UPDATED = 'updated';

    private const int KEY_LENGTH = 40;

    /**
     * @param QshprClient $client
     * @param Config $config
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     * @param EncryptorInterface $encryptor
     * @param Random $random
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly QshprClient $client,
        private readonly Config $config,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Random $random,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register or re-point the webhook for a store.
     *
     * @param int $storeId Store whose base URL and Quickshipper login are used.
     * @return array{action: string, webhookId: int, url: string} action ACTION_CREATED or ACTION_UPDATED;
     *     url without the key, for display.
     * @throws QshprApiException
     */
    public function connect(int $storeId): array
    {
        $base = rtrim($this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/')
            . '/' . self::WEBHOOK_PATH;
        $key = $this->key();
        $url = $base . '?key=' . rawurlencode($key);
        $existing = $this->findOurs($base, $storeId);

        if ($existing !== null) {
            $this->client->updateWebhook($existing, $url, $key, $storeId);
            $action = self::ACTION_UPDATED;
            $webhookId = $existing;
        } else {
            $webhookId = $this->client->createWebhook($url, $key, $storeId);
            $action = self::ACTION_CREATED;
        }

        $this->logger->info(sprintf('webhook: %s Quickshipper webhook %d → %s', $action, $webhookId, $base));

        return ['action' => $action, 'webhookId' => $webhookId, 'url' => $base];
    }

    /**
     * The installation's webhook key; a new one is generated and stored on first use.
     *
     * @return string
     */
    private function key(): string
    {
        $key = $this->config->getWebhookKey();

        if ($key === '') {
            $key = $this->random->getRandomString(self::KEY_LENGTH);
            $this->configWriter->save($this->config->getWebhookKeyPath(), $this->encryptor->encrypt($key));
            $this->reinitableConfig->reinit();
        }

        return $key;
    }

    /**
     * Id of the status webhook already registered for this address, or null.
     *
     * @param string $base Webhook URL without the query string.
     * @param int $storeId
     * @return int|null
     * @throws QshprApiException
     */
    private function findOurs(string $base, int $storeId): ?int
    {
        foreach ($this->client->listWebhooks($storeId) as $webhook) {
            $url = (string)($webhook['callBackUrl'] ?? $webhook['callbackUrl'] ?? '');

            // Matched by address alone. The list also gives each webhook's type
            // ("OrderStatusChange"), but nothing else registers at this address.
            if (strtok($url, '?') === $base && is_numeric($webhook['id'] ?? null)) {
                return (int)$webhook['id'];
            }
        }

        return null;
    }
}
