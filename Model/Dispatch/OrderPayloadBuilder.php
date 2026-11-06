<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Dispatch;

use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Shubo\Qshpr\Exception\DispatchException;
use Shubo\Qshpr\Exception\PickupNotConfiguredException;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * Builds the body of POST /v1/Order: where the courier collects, where it
 * delivers, and which courier price is booked.
 *
 * Quickshipper routes by coordinates (it does not geocode), so both points
 * carry them; the address text is what the courier reads. Pickup name and
 * phone come from Magento's Store Information. Phone numbers are sent as
 * they were entered, with the Georgian country prefix.
 */
class OrderPayloadBuilder
{
    /**
     * Quickshipper takes the country code separately from the number.
     */
    private const string PHONE_PREFIX = '+995';

    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * The order-create body.
     *
     * @param OrderAddressInterface $dropOff The order's shipping address.
     * @param float $dropOffLatitude
     * @param float $dropOffLongitude
     * @param int $providerId Quickshipper's courier id.
     * @param string $providerFeeId The courier price id from a fresh fees answer ("asap", "85", …).
     * @param int|null $deliverySpeedId As the fees answer gave it (null for some couriers).
     * @param string $incrementId Magento order number, shown in the Quickshipper portal.
     * @param float $cartAmount Order subtotal in GEL.
     * @param float $weightKg Shipment weight; 0 when unknown.
     * @param int|null $storeId
     * @param bool $carDelivery Ask for a courier with a car.
     * @return array<string, mixed>
     * @throws DispatchException When the pickup point is incomplete or the customer has no phone.
     */
    public function build(
        OrderAddressInterface $dropOff,
        float $dropOffLatitude,
        float $dropOffLongitude,
        int $providerId,
        string $providerFeeId,
        ?int $deliverySpeedId,
        string $incrementId,
        float $cartAmount,
        float $weightKg,
        ?int $storeId,
        bool $carDelivery = false,
    ): array {
        try {
            $pickup = $this->config->getPickupPoint($storeId);
        } catch (PickupNotConfiguredException $e) {
            throw new DispatchException(new Phrase($e->getRawMessage(), $e->getParameters()), $e);
        }

        $customerPhone = trim((string)$dropOff->getTelephone());

        if ($customerPhone === '') {
            throw new DispatchException(__('The order\'s shipping address has no phone number for the courier.'));
        }

        $streetLines = (array)$dropOff->getStreet();
        $dropOffInfo = [
            'address' => trim((string)($streetLines[0] ?? '')),
            'city' => trim((string)$dropOff->getCity()),
            'latitude' => $dropOffLatitude,
            'longitude' => $dropOffLongitude,
            'name' => trim($dropOff->getFirstname() . ' ' . $dropOff->getLastname()),
            'phonePrefix' => self::PHONE_PREFIX,
            'phone' => $customerPhone,
        ];

        // Street line 2 (flat, floor, entrance) is what the courier needs at the door.
        $addressComment = trim(implode(', ', array_slice($streetLines, 1)));

        if ($addressComment !== '') {
            $dropOffInfo['addressComment'] = $addressComment;
        }

        $provider = ['providerId' => $providerId, 'providerFeeId' => $providerFeeId];

        if ($deliverySpeedId !== null) {
            $provider['deliverySpeedId'] = $deliverySpeedId;
        }

        $payload = [
            'pickUpInfo' => [
                'address' => $pickup->street,
                'city' => $pickup->city,
                'latitude' => $pickup->latitude,
                'longitude' => $pickup->longitude,
                'name' => $pickup->name,
                'phonePrefix' => self::PHONE_PREFIX,
                'phone' => $pickup->phone,
            ],
            'dropOffInfo' => $dropOffInfo,
            'provider' => $provider,
            'integrationOrderId' => $incrementId,
            'cartAmount' => round($cartAmount, 2),
        ];

        if ($weightKg > 0) {
            $payload['cartWeight'] = $weightKg;
        }

        if ($carDelivery) {
            $payload['carDelivery'] = true;
        }

        return $payload;
    }
}
