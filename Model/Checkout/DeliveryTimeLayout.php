<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;

/**
 * Puts the "Delivery time" list into Luma's shipping step, in the area under
 * the delivery options (shippingAdditional).
 */
class DeliveryTimeLayout implements LayoutProcessorInterface
{
    private const string COMPONENT = 'Shubo_Qshpr/js/view/delivery-time';
    private const string AREA = 'shippingAdditional';

    /**
     * Add the delivery time list to the shipping step, when the page has one.
     *
     * @param array<string,mixed> $jsLayout
     * @return array<string,mixed>
     */
    public function process($jsLayout)
    {
        if (!isset($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress'])
        ) {
            return $jsLayout;
        }

        $shippingAddress = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress'];
        $area = &$shippingAddress['children'][self::AREA];
        $area['component'] ??= 'uiComponent';
        $area['displayArea'] ??= self::AREA;
        $area['children']['shubo-qshpr-delivery-time'] = ['component' => self::COMPONENT];

        return $jsLayout;
    }
}
