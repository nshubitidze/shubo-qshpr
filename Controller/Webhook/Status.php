<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Controller\Webhook;

use Laminas\Http\Response as HttpResponse;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Status\StatusUpdater;

/**
 * Quickshipper's OrderStatusChange webhook: POST /qshpr/webhook/status?key=…
 *
 * Quickshipper does not sign webhooks, so the body is only a hint: its
 * OrderId says which delivery to bring up to date, and StatusUpdater reads
 * the order from Quickshipper itself. The installation's webhook key keeps
 * strangers from making the store call Quickshipper. It arrives in the
 * "apikey" header (the webhook's apiKey, sent on every call) or, for
 * webhooks connected before that and until the header is seen working in
 * production, in the URL.
 *
 * Answers: 200 handled (or not one of our bookings), 400 unreadable body,
 * 403 wrong key, 503 try again later (Quickshipper did not answer, the order
 * was busy, anything unexpected) — Quickshipper repeats a call that was not
 * accepted, up to the retry count set when the webhook was registered.
 */
class Status implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const string PARAM_KEY = 'key';
    private const string HEADER_KEY = 'apikey';

    /**
     * @param RequestInterface $request
     * @param RawFactory $rawFactory
     * @param StatusUpdater $statusUpdater
     * @param Config $config
     * @param Json $json
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly StatusUpdater $statusUpdater,
        private readonly Config $config,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle one webhook call.
     *
     * @return Raw
     */
    public function execute(): Raw
    {
        try {
            $keyFrom = $this->keyFrom($this->config->getWebhookKey());

            if ($keyFrom === null) {
                $this->logger->warning('webhook: refused a call with a missing or wrong key');

                return $this->answer(HttpResponse::STATUS_CODE_403, 'forbidden');
            }

            $qshprOrderId = $this->orderId();

            if ($qshprOrderId === null) {
                $this->logger->warning('webhook: body without a readable OrderId');

                return $this->answer(HttpResponse::STATUS_CODE_400, 'no OrderId');
            }

            $result = $this->statusUpdater->update($qshprOrderId);
            $this->logger->info(sprintf(
                'webhook: Quickshipper order %d → %s (key from %s)',
                $qshprOrderId,
                $result->value,
                $keyFrom
            ));

            return $this->answer($result->httpStatus(), $result->value);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('webhook: failed, Quickshipper will retry: %s', $e->getMessage()),
                ['exception' => $e]
            );

            return $this->answer(HttpResponse::STATUS_CODE_503, 'try again');
        }
    }

    /**
     * Where the call's correct key came from ("header" or "url"); null when neither has it.
     *
     * @param string $expected The installation's webhook key; '' when the store was never connected.
     * @return string|null
     */
    private function keyFrom(string $expected): ?string
    {
        if ($expected === '') {
            return null;
        }

        $header = $this->request instanceof HttpRequest ? $this->request->getHeader(self::HEADER_KEY) : false;

        if (is_string($header) && hash_equals($expected, $header)) {
            return 'header';
        }

        return hash_equals($expected, (string)$this->request->getParam(self::PARAM_KEY, '')) ? 'url' : null;
    }

    /**
     * Quickshipper's order id from the body (PascalCase "OrderId"); null when unreadable.
     *
     * @return int|null
     */
    private function orderId(): ?int
    {
        $content = $this->request instanceof HttpRequest ? (string)$this->request->getContent() : '';

        try {
            $body = $this->json->unserialize($content);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $orderId = is_array($body) ? ($body['OrderId'] ?? $body['orderId'] ?? null) : null;

        return is_numeric($orderId) && (int)$orderId > 0 ? (int)$orderId : null;
    }

    /**
     * Plain-text answer with an HTTP status.
     *
     * @param int $httpStatus
     * @param string $text
     * @return Raw
     */
    private function answer(int $httpStatus, string $text): Raw
    {
        return $this->rawFactory->create()
            ->setHttpResponseCode($httpStatus)
            ->setHeader('Content-Type', 'text/plain', true)
            ->setContents($text);
    }

    /**
     * Quickshipper cannot send Magento's form key; the webhook key is checked in execute().
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Skip Magento's form-key check for this endpoint.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
