<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model;

use Laminas\Http\Response as HttpResponse;
use Magento\Framework\HTTP\AsyncClient\Request;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * Quickshipper Delivery API client. Returns validated response arrays;
 * building request payloads and interpreting results belong to the callers.
 *
 * Quickshipper answers in three shapes: a business envelope that is HTTP 200
 * even on failure (success:false + the real code in the body's
 * httpStatusCode), gateway auth errors (401 bad token, 417 missing token) and
 * ASP.NET validation errors (400 problem+json with a traceId). So HTTP 200
 * alone never counts as success.
 *
 * Every call needs a bearer token from an OAuth2 password grant with the
 * merchant's dashboard login, on a separate host. The token request must be
 * form-encoded — the JSON body shown in Quickshipper's docs is rejected — and
 * its answer has no refresh_token, so an expired or rejected token means a
 * fresh login.
 */
class QshprClient
{
    private const string GRANT_TYPE = 'password';
    private const string SCOPE = 'DeliveryApi';

    /** Webhook type for order status changes. */
    public const string WEBHOOK_TYPE = 'OrderStatusChange';

    /** How often Quickshipper repeats a webhook call our store did not accept. */
    private const int WEBHOOK_RETRIES = 3;

    /**
     * @param CurlFactory $curlFactory
     * @param TokenCache $tokenCache
     * @param Config $config
     * @param Json $json
     * @param LogSanitizer $logSanitizer
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly TokenCache $tokenCache,
        private readonly Config $config,
        private readonly Json $json,
        private readonly LogSanitizer $logSanitizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The merchant's Quickshipper account (shopName, balance, hasProviders, …).
     *
     * Also the cheapest proof that the credentials work.
     *
     * @param int|null $storeId
     * @return array<string, mixed>
     * @throws QshprApiException
     */
    public function getAccount(?int $storeId = null): array
    {
        return $this->request(Endpoint::Account, [], $storeId);
    }

    /**
     * Delivery quotes from every courier for one trip.
     *
     * An empty "fees" list is Quickshipper's normal "no delivery offered"
     * answer (e.g. intercity), not an error. Coordinates must always be
     * sent: Quickshipper does not geocode, so missing ones silently become
     * 0,0 and still get a meaningless quote.
     *
     * @param array<string, string|int|float|bool> $query FromLatitude, FromLongitude, ToLatitude,
     *     ToLongitude, FromStreetName, FromCityName, ToStreetName, ToCityName, CartWeight (kg),
     *     CartAmount (GEL), … — the query parameters of GET /v1/Order/fees.
     * @param int|null $storeId
     * @return array<string, mixed> Envelope with "fees" (one entry per courier) and "distance" (km).
     * @throws QshprApiException
     */
    public function getFees(array $query, ?int $storeId = null): array
    {
        return $this->request(Endpoint::Fees, $query, $storeId);
    }

    /**
     * Book a courier: create a Quickshipper order.
     *
     * The delivery price is taken from the merchant's Quickshipper balance at
     * once. Whether the order then goes to the courier on its own
     * (ReadyForPickup) or waits as a Draft for confirmOrder() depends on the
     * merchant's Quickshipper dashboard setting. A refused create (e.g. "Not enough
     * balance", a courier-side failure) is success:false and may still leave
     * a cancelled order in the merchant's Quickshipper order list.
     *
     * @param array<string, mixed> $payload Body of POST /v1/Order (pickUpInfo, dropOffInfo, provider, …).
     * @param int|null $storeId
     * @return array<string, mixed> Envelope with orderId, orderStatus, trackingUrl, deliveryFee, …
     * @throws QshprApiException
     */
    public function createOrder(array $payload, ?int $storeId = null): array
    {
        return $this->request(Endpoint::CreateOrder, [], $storeId, $payload);
    }

    /**
     * Send a Draft order on to the courier company.
     *
     * Needed when the merchant's Quickshipper dashboard is set to assign the
     * courier "after confirmation": every new order is then a Draft. The
     * delivery price was already taken when the order was created; confirming
     * takes nothing more.
     *
     * @param int $orderId Quickshipper's order id.
     * @param int|null $storeId
     * @return array<string, mixed> Envelope with orderStatus (ReadyForPickup once confirmed).
     * @throws QshprApiException
     */
    public function confirmOrder(int $orderId, ?int $storeId = null): array
    {
        return $this->request(Endpoint::ConfirmOrder, [], $storeId, ['orderId' => $orderId]);
    }

    /**
     * Cancel a Quickshipper order.
     *
     * Works only before a courier is assigned, and never on a Draft that was
     * not confirmed. A cancel before a courier returns the delivery price;
     * Quickshipper may keep its service fee.
     *
     * @param int $orderId Quickshipper's order id.
     * @param string $comment Reason, shown on the order in the Quickshipper dashboard.
     * @param int|null $storeId
     * @return array<string, mixed>
     * @throws QshprApiException
     */
    public function cancelOrder(int $orderId, string $comment, ?int $storeId = null): array
    {
        return $this->request(Endpoint::ChangeOrderStatus, [], $storeId, [
            'orderId' => $orderId,
            'status' => OrderStatus::Cancelled->value,
            'orderComment' => $comment,
        ]);
    }

    /**
     * One Quickshipper order with its status, number (orderNo), price and tracking link.
     *
     * @param int $orderId Quickshipper's order id.
     * @param int|null $storeId
     * @return array<string, mixed> Envelope with "order", "courier", "provider", "customer", "shop".
     * @throws QshprApiException
     */
    public function getOrder(int $orderId, ?int $storeId = null): array
    {
        return $this->request(Endpoint::GetOrder, ['OrderId' => $orderId], $storeId);
    }

    /**
     * The account's registered webhooks.
     *
     * Quickshipper has no way to delete a webhook; one is switched off or
     * re-pointed with updateWebhook().
     *
     * @param int|null $storeId
     * @return array<int, array<string, mixed>> Each with id, callBackUrl, type, isActive, maxRetryCount.
     * @throws QshprApiException
     */
    public function listWebhooks(?int $storeId = null): array
    {
        $webhooks = $this->request(Endpoint::ListWebhooks, [], $storeId)['webhooks'] ?? [];

        return array_values(array_filter((array)$webhooks, 'is_array'));
    }

    /**
     * Register a webhook: Quickshipper POSTs to $callbackUrl on every order status change.
     *
     * @param string $callbackUrl
     * @param int|null $storeId
     * @return int The new webhook's id.
     * @throws QshprApiException
     */
    public function createWebhook(string $callbackUrl, ?int $storeId = null): int
    {
        $answer = $this->request(Endpoint::CreateWebhook, [], $storeId, [
            'callBackUrl' => $callbackUrl,
            'type' => self::WEBHOOK_TYPE,
            'maxRetryCount' => self::WEBHOOK_RETRIES,
        ]);

        return is_numeric($answer['webhookId'] ?? null) ? (int)$answer['webhookId'] : 0;
    }

    /**
     * Point an existing webhook at $callbackUrl and switch it on.
     *
     * Quickshipper spells the field callbackUrl here but callBackUrl when
     * creating and listing.
     *
     * @param int $webhookId
     * @param string $callbackUrl
     * @param int|null $storeId
     * @return void
     * @throws QshprApiException
     */
    public function updateWebhook(int $webhookId, string $callbackUrl, ?int $storeId = null): void
    {
        $this->request(Endpoint::UpdateWebhook, [], $storeId, [
            'id' => $webhookId,
            'callbackUrl' => $callbackUrl,
            'type' => self::WEBHOOK_TYPE,
            'isActive' => true,
            'maxRetryCount' => self::WEBHOOK_RETRIES,
        ]);
    }

    /**
     * Send an authenticated request to the Delivery API; validate and unwrap the response.
     *
     * A 401 means the cached token is no longer accepted: it is dropped and
     * the request is repeated once with a fresh login.
     *
     * @param Endpoint $endpoint
     * @param array<string, string|int|float|bool> $query
     * @param int|null $storeId
     * @param array<string, mixed>|null $payload JSON body (POST operations).
     * @param bool $isRetry
     * @return array<string, mixed>
     * @throws QshprApiException
     */
    private function request(
        Endpoint $endpoint,
        array $query,
        ?int $storeId,
        ?array $payload = null,
        bool $isRetry = false,
    ): array {
        $label = $endpoint->label();
        $token = $this->tokenCache->load($storeId) ?? $this->login($storeId);

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug("$label request", [
                'query' => $this->logSanitizer->mask($query),
                'payload' => $this->logSanitizer->mask($payload ?? []),
            ]);
        }

        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        [$status, $body, $elapsedMs] = $this->send(
            $endpoint,
            $query,
            $headers,
            $payload !== null ? $this->json->serialize($payload) : '',
            $storeId,
        );

        if ($status === HttpResponse::STATUS_CODE_401 && !$isRetry) {
            $this->logger->warning(sprintf(
                '%s returned HTTP 401 after %dms — token rejected, logging in again and retrying once',
                $label,
                $elapsedMs
            ));
            $this->tokenCache->remove($storeId);

            return $this->request($endpoint, $query, $storeId, $payload, isRetry: true);
        }

        $decoded = $this->decode($body);

        if ($status !== HttpResponse::STATUS_CODE_200) {
            $traceId = is_array($decoded) && is_scalar($decoded['traceId'] ?? null)
                ? (string)$decoded['traceId']
                : '-';

            $this->logger->error(sprintf(
                '%s returned HTTP %d after %dms (traceId: %s), body: %s',
                $label,
                $status,
                $elapsedMs,
                $this->logSanitizer->line($traceId),
                $this->logSanitizer->excerpt($body)
            ));

            throw new QshprApiException(
                __('Quickshipper returned HTTP %1 for %2 (traceId: %3).', $status, $label, $traceId),
                apiErrorCode: $status === HttpResponse::STATUS_CODE_400 ? HttpResponse::STATUS_CODE_400 : null
            );
        }

        if (!is_array($decoded)) {
            $this->logger->error(sprintf(
                '%s returned a non-JSON or non-object body after %dms, body: %s',
                $label,
                $elapsedMs,
                $this->logSanitizer->excerpt($body)
            ));

            throw new QshprApiException(__('Quickshipper returned an unreadable response for %1.', $label));
        }

        if (($decoded['success'] ?? null) !== true) {
            $apiCode = is_numeric($decoded['httpStatusCode'] ?? null) ? (int)$decoded['httpStatusCode'] : null;
            $message = $this->errorMessage($decoded);

            $this->logger->error(sprintf(
                '%s failed after %dms: httpStatusCode %s "%s"',
                $label,
                $elapsedMs,
                $apiCode ?? '-',
                $this->logSanitizer->line($message)
            ));

            throw new QshprApiException(
                __('Quickshipper error %1 for %2: %3', $apiCode ?? '-', $label, $message),
                apiErrorCode: $apiCode
            );
        }

        $this->logger->info(sprintf('%s succeeded in %dms', $label, $elapsedMs));

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug("$label response", ['response' => $this->logSanitizer->mask($decoded)]);
        }

        return $decoded;
    }

    /**
     * Run the OAuth password grant and cache the token.
     *
     * @param int|null $storeId
     * @return string
     * @throws QshprApiException
     */
    private function login(?int $storeId): string
    {
        $endpoint = Endpoint::Token;
        $clientId = $this->config->getClientId($storeId, strict: true);
        $clientSecret = $this->config->getClientSecret($storeId, strict: true);

        [$status, $body, $elapsedMs] = $this->send(
            $endpoint,
            [],
            [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            http_build_query([
                'grant_type' => self::GRANT_TYPE,
                'scope' => self::SCOPE,
                'username' => $this->config->getUsername($storeId, strict: true),
                'password' => $this->config->getPassword($storeId, strict: true),
            ]),
            $storeId,
        );

        $decoded = $this->decode($body);
        $token = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;

        if ($status !== HttpResponse::STATUS_CODE_200 || !is_string($token) || $token === '') {
            // OAuth error shape: {"error":"invalid_grant","error_description":"User not found"}.
            $error = is_array($decoded) && is_scalar($decoded['error'] ?? null) ? (string)$decoded['error'] : '-';
            $description = is_array($decoded) && is_scalar($decoded['error_description'] ?? null)
                ? (string)$decoded['error_description']
                : 'no token in response';

            $this->logger->error(sprintf(
                '%s login failed after %dms: HTTP %d, error %s "%s", body: %s',
                $endpoint->label(),
                $elapsedMs,
                $status,
                $this->logSanitizer->line($error),
                $this->logSanitizer->line($description),
                $this->logSanitizer->excerpt($body)
            ));

            throw new QshprApiException(
                __('Quickshipper login failed (HTTP %1): %2 %3', $status, $error, $description)
            );
        }

        $expiresIn = is_numeric($decoded['expires_in'] ?? null) ? (int)$decoded['expires_in'] : 0;
        $cachedFor = $this->tokenCache->save($token, $expiresIn, $storeId);

        $this->logger->info(sprintf(
            '%s login succeeded in %dms (token cached for %ds)',
            $endpoint->label(),
            $elapsedMs,
            $cachedFor
        ));

        return $token;
    }

    /**
     * Send one HTTP request and return the raw answer, whatever its status.
     *
     * @param Endpoint $endpoint Gives the host, path, HTTP method and log label.
     * @param array<string, string|int|float|bool> $query
     * @param array<string, string> $headers
     * @param string $body Request body (POST and PUT only).
     * @param int|null $storeId
     * @return array{int, string, int} HTTP status, body, round trip in milliseconds.
     * @throws QshprApiException On transport failure (DNS, TLS, timeout, …).
     */
    private function send(
        Endpoint $endpoint,
        array $query,
        array $headers,
        #[\SensitiveParameter] string $body,
        ?int $storeId,
    ): array {
        $url = ($endpoint->onAuthHost() ? $this->config->getAuthUrl($storeId) : $this->config->getApiUrl($storeId))
            . $endpoint->path()
            . ($query !== [] ? '?' . http_build_query($query) : '');
        $curl = $this->curlFactory->create();
        $curl->setHeaders($headers);
        $curl->setTimeout($this->config->getApiTimeout($storeId));

        $startedAt = microtime(true);

        try {
            match ($endpoint->method()) {
                Request::METHOD_POST => $curl->post($url, $body),
                Request::METHOD_GET => $curl->get($url),
                // Magento's Curl client has no put(): a POST with the method overridden sends the body as PUT.
                Request::METHOD_PUT => $this->put($curl, $url, $body),
            };
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '%s transport failure after %dms: %s',
                $endpoint->label(),
                $this->elapsedMs($startedAt),
                $this->logSanitizer->line($e->getMessage())
            ));

            throw new QshprApiException(
                __('Quickshipper request %1 failed: %2', $endpoint->label(), $e->getMessage()),
                $e
            );
        }

        return [$curl->getStatus(), $curl->getBody(), $this->elapsedMs($startedAt)];
    }

    /**
     * Send $body with the PUT method.
     *
     * @param Curl $curl
     * @param string $url
     * @param string $body
     * @return void
     */
    private function put(Curl $curl, string $url, #[\SensitiveParameter] string $body): void
    {
        $curl->setOption(CURLOPT_CUSTOMREQUEST, Request::METHOD_PUT);
        $curl->post($url, $body);
    }

    /**
     * JSON-decode a body; null when it is not valid JSON.
     *
     * @param string $body
     * @return mixed
     */
    private function decode(string $body): mixed
    {
        try {
            return $this->json->unserialize($body);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Best human-readable message of a success:false envelope.
     *
     * @param array<string, mixed> $envelope
     * @return string
     */
    private function errorMessage(array $envelope): string
    {
        foreach (['userMessage', 'developerMessage'] as $field) {
            if (is_scalar($envelope[$field] ?? null) && (string)$envelope[$field] !== '') {
                return (string)$envelope[$field];
            }
        }

        $errors = is_array($envelope['errors'] ?? null) ? array_filter($envelope['errors'], 'is_scalar') : [];

        return $errors !== [] ? implode('; ', $errors) : 'unknown error';
    }

    /**
     * Milliseconds since $startedAt.
     *
     * @param float $startedAt
     * @return int
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int)round((microtime(true) - $startedAt) * 1000);
    }
}
