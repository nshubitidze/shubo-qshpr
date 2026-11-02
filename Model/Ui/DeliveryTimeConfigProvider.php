<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Shubo\Qshpr\Model\Schedule\DeliverySlots;

/**
 * Gives checkout the delivery times a customer can choose
 * (window.checkoutConfig.shuboQshprDeliveryTime). Empty when the merchant
 * does not offer delivery times.
 */
class DeliveryTimeConfigProvider implements ConfigProviderInterface
{
    /**
     * @param DeliverySlots $deliverySlots
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly DeliverySlots $deliverySlots,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return array<string, array{carrier: string, slots: array<int, array{value: string, label: string}>}>
     */
    public function getConfig(): array
    {
        return ['shuboQshprDeliveryTime' => [
            'carrier' => ConfigProvider::CODE,
            'slots' => $this->deliverySlots->getSlots((int)$this->storeManager->getStore()->getId()),
        ]];
    }
}
