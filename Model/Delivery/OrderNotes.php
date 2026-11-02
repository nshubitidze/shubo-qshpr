<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Delivery;

use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Adds a note for the merchant to an order's history from work that runs
 * without the order being saved (crons): the note is saved on its own.
 */
class OrderNotes
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Add the note; a failure is logged, not thrown.
     *
     * @param int $orderId Magento order entity id.
     * @param Phrase $comment
     * @return void
     */
    public function add(int $orderId, Phrase $comment): void
    {
        try {
            $order = $this->orderRepository->get($orderId);

            if ($order instanceof Order) {
                $this->historyRepository->save($order->addCommentToStatusHistory($comment->render()));
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'could not add the order comment "%s" to order id %d: %s',
                $comment->render(),
                $orderId,
                $e->getMessage()
            ));
        }
    }
}
