<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model;

use Magento\Framework\HTTP\AsyncClient\Request;

/**
 * Quickshipper operations this module calls. The value names the operation;
 * path() is relative to its host's base URL — creating and reading an order
 * share the path /v1/Order and differ only by HTTP method.
 *
 * Cancelling is a status change: POST /v1/Order/status with the status
 * "Cancelled". Quickshipper has no separate cancel operation.
 */
enum Endpoint: string
{
    case Token = 'token';
    case Account = 'account';
    case Fees = 'fees';
    case CreateOrder = 'create_order';
    case GetOrder = 'get_order';
    case ConfirmOrder = 'confirm_order';
    case ChangeOrderStatus = 'change_order_status';
    case ListWebhooks = 'list_webhooks';
    case CreateWebhook = 'create_webhook';
    case UpdateWebhook = 'update_webhook';

    /**
     * Path of the operation, relative to the auth or Delivery API host.
     *
     * @return string
     */
    public function path(): string
    {
        return match ($this) {
            self::Token => '/connect/token',
            self::Account => '/v1/Account',
            self::Fees => '/v1/Order/fees',
            self::CreateOrder, self::GetOrder => '/v1/Order',
            self::ConfirmOrder => '/v1/Order/confirm',
            self::ChangeOrderStatus => '/v1/Order/status',
            self::ListWebhooks, self::CreateWebhook, self::UpdateWebhook => '/v1/Webhook',
        };
    }

    /**
     * Whether the operation is on the auth host rather than the Delivery API host.
     *
     * @return bool
     */
    public function onAuthHost(): bool
    {
        return $this === self::Token;
    }

    /**
     * HTTP method of the operation.
     *
     * @return string Request::METHOD_GET, METHOD_POST or METHOD_PUT
     */
    public function method(): string
    {
        return match ($this) {
            self::Token,
            self::CreateOrder,
            self::ConfirmOrder,
            self::ChangeOrderStatus,
            self::CreateWebhook => Request::METHOD_POST,
            self::Account, self::Fees, self::GetOrder, self::ListWebhooks => Request::METHOD_GET,
            self::UpdateWebhook => Request::METHOD_PUT,
        };
    }

    /**
     * "GET /v1/Order/fees"-style label for log lines and messages.
     *
     * @return string
     */
    public function label(): string
    {
        return $this->method() . ' ' . $this->path();
    }
}
