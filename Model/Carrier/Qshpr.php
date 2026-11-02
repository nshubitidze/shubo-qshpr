<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Shipping\Model\Tracking\Result\Status;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Model\CashOnDelivery\CashOnDelivery;
use Shubo\Qshpr\Model\Delivery\DeliveryLog;
use Shubo\Qshpr\Model\Money;
use Shubo\Qshpr\Model\OrderStatus;
use Shubo\Qshpr\Model\Rates\CourierLogos;
use Shubo\Qshpr\Model\Rates\RateQuoter;
use Shubo\Qshpr\Model\Ui\ConfigProvider;

/**
 * The Quickshipper shipping carrier: one checkout option per courier and
 * delivery speed, at the price Quickshipper quotes.
 *
 * Quickshipper's price includes its service fee per order; the merchant
 * chooses whether the customer pays it or the shop does ("Customer Pays
 * Quickshipper's Service Fee"). The rate's cost is always the full price,
 * which is what Quickshipper takes from the merchant's balance. Magento's
 * handling fee is not offered, and free-shipping cart rules are not
 * applied: a promotion meant for another carrier must not make paid
 * courier deliveries free.
 */
class Qshpr extends AbstractCarrier implements CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = ConfigProvider::CODE;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     * @param ResultFactory $rateResultFactory
     * @param MethodFactory $rateMethodFactory
     * @param RateQuoter $rateQuoter
     * @param DeliveryLog $deliveryLog
     * @param StatusFactory $trackStatusFactory
     * @param CourierLogos $courierLogos
     * @param CashOnDelivery $cashOnDelivery
     * @param Config $config
     * @param array<string, mixed> $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly RateQuoter $rateQuoter,
        private readonly DeliveryLog $deliveryLog,
        private readonly StatusFactory $trackStatusFactory,
        private readonly CourierLogos $courierLogos,
        private readonly CashOnDelivery $cashOnDelivery,
        private readonly Config $config,
        array $data = [],
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Quickshipper courier rates for the request, or false when none can be offered.
     *
     * @param RateRequest $request
     * @return Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        try {
            $offers = $this->rateQuoter->quote($request);
        } catch (\Throwable $e) {
            // A bug here must not break checkout for every shipping method.
            $this->_logger->critical('rates: unexpected failure: ' . $e->getMessage(), ['exception' => $e]);

            return false;
        }

        if ($offers === []) {
            return false;
        }

        $result = $this->rateResultFactory->create();
        $carrierTitle = (string)$this->getConfigData('title');
        $storeId = $request->getStoreId() !== null ? (int)$request->getStoreId() : null;
        $customerPaysFee = $this->config->isServiceFeeChargedToCustomer($storeId);

        foreach ($offers as $offer) {
            $method = $this->rateMethodFactory->create();
            $method->setCarrier($this->_code);
            $method->setCarrierTitle($carrierTitle);
            $method->setMethod($offer->methodCode());
            $method->setMethodTitle($offer->title());
            $method->setPrice(Money::toGelAmount($offer->customerPrice(includeServiceFee: $customerPaysFee)));
            $method->setCost(Money::toGelAmount($offer->amount));
            $result->append($method);
            $this->courierLogos->remember($offer->providerId, $offer->logoUrl);
            $this->cashOnDelivery->remember($offer->providerId, $offer->collectsCash);
        }

        return $result;
    }

    /**
     * Shipments carry the Quickshipper order number as their tracking number.
     *
     * @return bool
     */
    public function isTrackingAvailable()
    {
        return true;
    }

    /**
     * Answer for Magento's tracking popup: a link to Quickshipper's tracking
     * page for the order number, or false when the number is unknown.
     *
     * The delivery status goes into the carrier line ("Quickshipper Wolt ·
     * On the way"): Magento's popup shows either a link or a status field,
     * never both, and the link matters more.
     *
     * @param string $tracking Quickshipper order number, e.g. "SQ42609".
     * @return Status|false
     */
    public function getTrackingInfo($tracking)
    {
        $delivery = $this->deliveryLog->findByOrderNo((string)$tracking);

        if ($delivery === null) {
            return false;
        }

        $status = $this->trackStatusFactory->create();
        $status->setCarrier($this->_code);
        $deliveryStatus = OrderStatus::fromAnswer($delivery['qshpr_status'] ?? null);
        $status->setCarrierTitle(trim((string)$this->getConfigData('title') . ' ' . $delivery['provider_name'])
            . ($deliveryStatus !== null ? ' · ' . $deliveryStatus->label() : ''));
        $status->setTracking((string)$tracking);

        if (is_string($delivery['tracking_url']) && $delivery['tracking_url'] !== '') {
            $status->setUrl($delivery['tracking_url']);
        }

        return $status;
    }

    /**
     * Courier/speed methods are dynamic (whatever Quickshipper quotes), so
     * the carrier reports itself as the one allowed method.
     *
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return [$this->_code => (string)$this->getConfigData('title')];
    }
}
