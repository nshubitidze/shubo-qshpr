<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Shubo\Qshpr\Model\Schedule\DeliverySlots;
use Shubo\Qshpr\Model\Schedule\OpeningHours;

/**
 * Gives checkout the delivery times a customer can choose
 * (window.checkoutConfig.shuboQshprDeliveryTime). Empty when the merchant
 * does not offer delivery times. "asap" is false while the shop is closed
 * and keeps opening hours: the customer then has to pick a time.
 */
class DeliveryTimeConfigProvider implements ConfigProviderInterface
{
    /**
     * @param DeliverySlots $deliverySlots
     * @param StoreManagerInterface $storeManager
     * @param OpeningHours $openingHours
     */
    public function __construct(
        private readonly DeliverySlots $deliverySlots,
        private readonly StoreManagerInterface $storeManager,
        private readonly OpeningHours $openingHours,
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return array<string, array{carrier: string, asap: bool, slots: array<int, array{value: string, label: string}>}>
     */
    public function getConfig(): array
    {
        $storeId = (int)$this->storeManager->getStore()->getId();

        return ['shuboQshprDeliveryTime' => [
            'carrier' => ConfigProvider::CODE,
            'asap' => $this->openingHours->nextOpening($storeId) === null,
            'slots' => $this->deliverySlots->getSlots($storeId),
        ]];
    }
}
