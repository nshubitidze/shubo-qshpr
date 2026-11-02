<?php
// Checks for the checkout carrier: Carrier\Qshpr, Rates\RateQuoter, Rates\Offer.
//  1. config: pickup coordinates, carrier defaults
//  2. carrier + quoter over a stub geocoder and a stub client: rates, query,
//     weight, who pays Quickshipper's service fee, no free shipping, cache,
//     every "not offered" path
//  2b. courier logos: remembered from the fees answer, put on the checkout's
//     shipping methods by Plugin\ShippingMethodLogo, switch off
//  2c. cash on delivery: courier's hasCashOnDelivery remembered, cash payment
//     hidden for a courier that cannot collect it (Observer\HideCashOnDelivery)
//  2d. car delivery: the rule (the merchant's Yes/No attribute, weight,
//     default), the cart's items, the attribute loaded with cart items,
//     the settings list, CarDelivery=true in the fees query
//  2e. delivery times: slots from the settings in store time, couriers whose
//      hasScheduledDelivery is true
//     only, the shipping step keeps only an offered slot (Plugin\SaveDeliveryTime)
//  3. LIVE sandbox: a Tbilisi address gets courier rates, Batumi (intercity) none
//  4. log assertions on var/log/shubo_qshpr.log (this run's lines only)
//
// Sandbox credentials for section 3: env QSHPR_USERNAME / QSHPR_PASSWORD, else a
// local, untracked .qshpr.env file in the Magento root. Nothing is written to the
// database and no Quickshipper order is created.
// QSHPR_OFFLINE=1 skips the live section: nothing is sent to Quickshipper.
//
// Run inside phpfpm from the Magento root: php <module-dir>/dev/checks/qshpr-carrier-check.php

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Cart\ShippingMethodConverter;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Carrier\Config;
use Shubo\Qshpr\Model\Carrier\Qshpr;
use Shubo\Qshpr\Model\Geocoding\Address;
use Shubo\Qshpr\Model\Geocoding\Geocoder;
use Shubo\Qshpr\Model\Geocoding\Location;
use Shubo\Qshpr\Model\Geocoding\Precision;
use Shubo\Qshpr\Model\CashOnDelivery\CashOnDelivery;
use Shubo\Qshpr\Model\QshprClient;
use Shubo\Qshpr\Model\Rates\CourierLogos;
use Shubo\Qshpr\Plugin\ShippingMethodLogo;
use Shubo\Qshpr\Observer\HideCashOnDelivery;
use Shubo\Qshpr\Model\Config\Source\YesNoProductAttribute;
use Shubo\Qshpr\Plugin\QuoteItemCarDeliveryAttribute;
use Shubo\Qshpr\Model\Rates\CarDeliveryRule;
use Shubo\Qshpr\Model\Schedule\DeliverySlots;
use Shubo\Qshpr\Model\Schedule\ScheduledCouriers;
use Shubo\Qshpr\Plugin\ShippingMethodDeliveryTime;
use Shubo\Qshpr\Plugin\SaveDeliveryTime;
use Shubo\Qshpr\Model\Rates\RateQuoter;
use Shubo\Qshpr\Model\TokenCache;
use Shubo\Qshpr\Model\WeightConverter;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

require __DIR__ . '/_stubs.php';

$logFile = BP . '/var/log/shubo_qshpr.log';
$logOffset = is_file($logFile) ? (int)filesize($logFile) : 0;

const P = 'carriers/shuboqshpr/';

$realScope = $om->get(ScopeConfigInterface::class);

// Pickup at Freedom Square, Tbilisi; store weights in lbs (Magento's default).
const PICKUP = [
    P . 'pickup_latitude' => '41.6934',
    P . 'pickup_longitude' => '44.8015',
    'shipping/origin/street_line1' => 'Freedom Square 1',
    'shipping/origin/city' => 'Tbilisi',
    'general/store_information/name' => 'Check Shop',
    'general/store_information/phone' => '322000000',
    'general/locale/weight_unit' => 'lbs',
];

/** Geocoder stand-in: always answers $answer, remembers what it was asked. */
function stubGeocoder(?Location $answer): Geocoder
{
    return new class ($answer) extends Geocoder {
        public int $calls = 0;
        public ?Address $asked = null;

        public function __construct(private readonly ?Location $answer)
        {
        }

        public function locate(Address $address, ?int $storeId = null): ?Location
        {
            $this->calls++;
            $this->asked = $address;

            return $this->answer;
        }
    };
}

/** Client stand-in: answers getFees() from a queue (an array, or a Throwable to throw). */
function stubClient(array $answers): QshprClient
{
    return new class ($answers) extends QshprClient {
        public array $queries = [];

        public function __construct(private array $answers)
        {
        }

        public function getFees(array $query, ?int $storeId = null): array
        {
            $this->queries[] = $query;
            $answer = array_shift($this->answers);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer ?? throw new \RuntimeException('unexpected getFees() call');
        }
    };
}

function quoter($om, Geocoder $geocoder, QshprClient $client, array $overrides = [], $cache = null): RateQuoter
{
    $config = $om->create(Config::class, [
        'scopeConfig' => overrideScope($overrides + PICKUP, $om->get(ScopeConfigInterface::class)),
    ]);

    return $om->create(RateQuoter::class, [
        'geocoder' => $geocoder,
        'client' => $client,
        'config' => $config,
        'weightConverter' => $om->create(WeightConverter::class, ['config' => $config]),
        'carDeliveryRule' => $om->create(CarDeliveryRule::class, ['config' => $config]),
        'cache' => $cache ?? memoryCache(),
    ]);
}

function carrier(
    $om,
    RateQuoter $quoter,
    array $overrides = [],
    ?CourierLogos $logos = null,
    ?CashOnDelivery $cod = null,
    ?ScheduledCouriers $scheduled = null,
): Qshpr
{
    $scope = overrideScope($overrides + [P . 'active' => '1'], $om->get(ScopeConfigInterface::class));

    return $om->create(Qshpr::class, [
        'rateQuoter' => $quoter,
        'config' => $om->create(Config::class, ['scopeConfig' => $scope]),
        // In-memory caches: the checks must not leave logos or courier answers in the store's cache.
        'courierLogos' => $logos ?? $om->create(CourierLogos::class, ['cache' => memoryCache()]),
        'cashOnDelivery' => $cod ?? $om->create(CashOnDelivery::class, ['cache' => memoryCache()]),
        'scheduledCouriers' => $scheduled ?? $om->create(ScheduledCouriers::class, ['cache' => memoryCache()]),
        'scopeConfig' => $scope,
    ]);
}

function rateRequest($om, array $data = []): RateRequest
{
    return $om->create(RateRequest::class)->setData($data + [
        'dest_street' => "ია კარგარეთელის ქუჩა 6\nბინა 5, სართული 2",
        'dest_city' => 'თბილისი',
        'dest_country_id' => 'GE',
        'package_weight' => 2.0,
        'package_value' => 50.0,
        'store_id' => 1,
    ]);
}

/** @return array<int, array{code: string, title: string, price: float, cost: float, carrier: string, carrierTitle: string}> */
function rates($result): array
{
    if (!$result) {
        return [];
    }

    return array_map(static fn ($m): array => [
        'code' => $m->getMethod(),
        'title' => $m->getMethodTitle(),
        'price' => round((float)$m->getPrice(), 2),
        'cost' => round((float)$m->getCost(), 2),
        'carrier' => $m->getCarrier(),
        'carrierTitle' => $m->getCarrierTitle(),
    ], $result->getAllRates());
}

$tbilisi = new Location(41.715799, 44.794258, Precision::House, 'თბილისი', 'stub');
// Shapes as the sandbox answered on 2026-09-30: Wolt/Glovo have
// deliverySpeedId null, OnWay's ids are its weight bracket.
$feesAnswer = ['success' => true, 'distance' => 3.1, 'fees' => [
    ['providerId' => 2, 'providerName' => 'Wolt', 'isActive' => true, 'serviceFee' => 0.6,
        'prices' => [['id' => 'asap', 'amount' => 14.32, 'currencyName' => 'GEL', 'deliverySpeedId' => null,
            'deliverySpeedName' => '45-60 min delivery⚡']]],
    ['providerId' => 25, 'providerName' => 'OnWay', 'isActive' => true, 'serviceFee' => 0.6,
        'prices' => [['id' => '85', 'amount' => 6.6, 'currencyName' => 'GEL', 'deliverySpeedId' => 85,
            'deliverySpeedName' => '4 days delivery']]],
    ['providerId' => 12, 'providerName' => 'Glovo', 'isActive' => false, 'serviceFee' => 0.6,
        'prices' => [['id' => 'ASAP', 'amount' => 9.01, 'currencyName' => 'GEL', 'deliverySpeedId' => null]]],
    ['providerId' => 99, 'providerName' => 'Odd', 'prices' => [
        ['id' => 'a', 'amount' => 5, 'currencyName' => 'USD'],
        ['id' => 'b', 'amount' => 0, 'currencyName' => 'GEL'],
        ['id' => 'c', 'amount' => 'x', 'currencyName' => 'GEL'],
        ['id' => '--', 'amount' => 3, 'currencyName' => 'GEL'],
        ['amount' => 3, 'currencyName' => 'GEL'],
    ]],
    'garbage',
]];

// --- 1. Config ---
// Read the module's own defaults: the store may have changed them (e.g. enabled the carrier).
$defaults = simplexml_load_file(dirname(__DIR__, 2) . '/etc/config.xml')->default->carriers->shuboqshpr;
check('config.xml: carrier disabled by default', (string)$defaults->active === '0');
check('config.xml: carrier model', (string)$defaults->model === Qshpr::class);
check('config.xml: customer pays the service fee by default (Quickshipper\'s full price)',
    (string)$defaults->customer_pays_service_fee === '1');
$fields = simplexml_load_file(dirname(__DIR__, 2) . '/etc/adminhtml/system.xml')
    ->xpath('//group[@id="shuboqshpr"]/field/@id');
$fieldIds = array_map('strval', $fields);
check('system.xml: Magento handling fee fields removed, service fee choice offered',
    !in_array('handling_type', $fieldIds, true) && !in_array('handling_fee', $fieldIds, true)
    && in_array('customer_pays_service_fee', $fieldIds, true));
check('config.xml: no handling fee defaults', !isset($defaults->handling_type) && !isset($defaults->handling_action));
check('config.xml: Georgia only by default', (string)$defaults->sallowspecific === '1'
    && (string)$defaults->specificcountry === 'GE');
$cfg = static fn (array $o): Config => $om->create(Config::class, ['scopeConfig' => overrideScope($o, $realScope)]);
check('config: pickup latitude parsed (spaces trimmed)', $cfg([P . 'pickup_latitude' => ' 41.7151 '])->getPickupLatitude() === 41.7151);
check('config: non-numeric latitude → null', $cfg([P . 'pickup_latitude' => 'Tbilisi'])->getPickupLatitude() === null);
check('config: latitude out of range → null', $cfg([P . 'pickup_latitude' => '95'])->getPickupLatitude() === null);
check('config: longitude 180 allowed, 181 not', $cfg([P . 'pickup_longitude' => '180'])->getPickupLongitude() === 180.0
    && $cfg([P . 'pickup_longitude' => '181'])->getPickupLongitude() === null);
check('config: zero coordinate → null (not set)', $cfg([P . 'pickup_latitude' => '0'])->getPickupLatitude() === null
    && $cfg([P . 'pickup_longitude' => '0.0'])->getPickupLongitude() === null);

// --- 2. Carrier + quoter over stubs ---
$geo = stubGeocoder($tbilisi);
$client = stubClient([$feesAnswer]);
$cache = memoryCache();
$carrier = carrier($om, quoter($om, $geo, $client, [], $cache), [P . 'customer_pays_service_fee' => '1']);
$rates = rates($carrier->collectRates(rateRequest($om)));
check('rates: one per active courier GEL price, cheapest first', array_column($rates, 'code') === ['25_85', '2_asap'],
    implode(', ', array_column($rates, 'code')));
check('rates: titles "courier – speed"', array_column($rates, 'title') === ['OnWay – 4 days delivery', 'Wolt – 45-60 min delivery⚡']);
check('rates: customer pays Quickshipper\'s full price (service fee included)', array_column($rates, 'price') === [6.6, 14.32]);
check('rates: cost = Quickshipper\'s full price, the fee not added twice', array_column($rates, 'cost') === [6.6, 14.32]);
check('rates: Wolt offered although its deliverySpeedId is null (live shape)', in_array('2_asap', array_column($rates, 'code'), true));
check('rates: carrier code and title', $rates[0]['carrier'] === 'shuboqshpr' && $rates[0]['carrierTitle'] === 'Quickshipper');
check('geocoder asked with street line 1 only, city, country',
    $geo->asked?->street === 'ია კარგარეთელის ქუჩა 6' && $geo->asked->city === 'თბილისი' && $geo->asked->countryId === 'GE');
$q = $client->queries[0];
check('query: pickup from config (origin text + coordinates)', $q['FromStreetName'] === 'Freedom Square 1'
    && $q['FromCityName'] === 'Tbilisi' && $q['FromLatitude'] === 41.6934 && $q['FromLongitude'] === 44.8015);
check('query: drop-off = typed text + geocoded coordinates', $q['ToStreetName'] === 'ია კარგარეთელის ქუჩა 6'
    && $q['ToCityName'] === 'თბილისი' && $q['ToLatitude'] === 41.715799 && $q['ToLongitude'] === 44.794258);
check('query: cart amount, weight 2 lbs → 0.907 kg', $q['CartAmount'] === 50.0 && $q['CartWeight'] === 0.907,
    json_encode(['amount' => $q['CartAmount'], 'weight' => $q['CartWeight'] ?? null]));

carrier($om, quoter($om, stubGeocoder($tbilisi), $c = stubClient([$feesAnswer]), ['general/locale/weight_unit' => 'kgs']))
    ->collectRates(rateRequest($om));
check('query: store in kg → weight sent as is', ($c->queries[0]['CartWeight'] ?? null) === 2.0);
carrier($om, quoter($om, stubGeocoder($tbilisi), $c = stubClient([$feesAnswer])))
    ->collectRates(rateRequest($om, ['package_weight' => 0]));
check('query: no product weight → no CartWeight', !array_key_exists('CartWeight', $c->queries[0]));

$again = rates($carrier->collectRates(rateRequest($om)));
check('cache: same trip again → no second Quickshipper call', count($client->queries) === 1 && $again === $rates);
$key = array_key_first($cache->data);
check('cache: 10 minutes, tagged, no address text in key or value', $cache->lifetimes[$key] === 600
    && in_array('SHUBO_QSHPR_RATES', $cache->tags[$key], true)
    && !str_contains($key, 'კარგარეთ') && !str_contains((string)$cache->data[$key], 'კარგარეთ'));

$shopPays = rates(carrier($om, quoter($om, stubGeocoder($tbilisi), stubClient([$feesAnswer])),
    [P . 'customer_pays_service_fee' => '0'])->collectRates(rateRequest($om)));
check('shop pays the fee → customer price without it', array_column($shopPays, 'price') === [6.0, 13.72],
    json_encode(array_column($shopPays, 'price')));
check('shop pays the fee → cost still the full price', array_column($shopPays, 'cost') === [6.6, 14.32]);
$waived = $feesAnswer;
$waived['fees'][0]['serviceFee'] = 0;
unset($waived['fees'][1]['serviceFee']);
$noFee = rates(carrier($om, quoter($om, stubGeocoder($tbilisi), stubClient([$waived])),
    [P . 'customer_pays_service_fee' => '0'])->collectRates(rateRequest($om)));
check('fee 0 or missing (fee-free account) → nothing taken off', array_column($noFee, 'price') === [6.6, 14.32]);
$promo = ['success' => true, 'fees' => [['providerId' => 2, 'providerName' => 'Wolt', 'serviceFee' => 0.6,
    'prices' => [['id' => 'asap', 'amount' => 0.5, 'currencyName' => 'GEL']]]]];
$promoRates = rates(carrier($om, quoter($om, stubGeocoder($tbilisi), stubClient([$promo])),
    [P . 'customer_pays_service_fee' => '0'])->collectRates(rateRequest($om)));
check('price below the fee (promo price) → 0, never negative', array_column($promoRates, 'price') === [0.0]);
$stale = rates(carrier($om, quoter($om, stubGeocoder($tbilisi), stubClient([$feesAnswer])),
    [P . 'customer_pays_service_fee' => '1', P . 'handling_type' => 'F', P . 'handling_fee' => '1.5'])
    ->collectRates(rateRequest($om)));
check('handling fee left in the store\'s settings is not added', array_column($stale, 'price') === [6.6, 14.32]);
$free = rates(carrier($om, quoter($om, stubGeocoder($tbilisi), stubClient([$feesAnswer])),
    [P . 'customer_pays_service_fee' => '1'])->collectRates(rateRequest($om, ['free_shipping' => 1])));
check('free-shipping cart rule does not make couriers free', array_column($free, 'price') === [6.6, 14.32]);

$off = carrier($om, quoter($om, $g = stubGeocoder($tbilisi), stubClient([])), [P . 'active' => '0']);
check('carrier disabled → no rates, nothing asked', $off->collectRates(rateRequest($om)) === false && $g->calls === 0);

check('empty fees list → no rates', carrier($om, quoter($om, stubGeocoder($tbilisi),
    stubClient([['success' => true, 'fees' => []]])))->collectRates(rateRequest($om)) === false);

$cache = memoryCache();
$client = stubClient([new QshprApiException(__('Quickshipper request GET /v1/Order/fees failed: timeout')), $feesAnswer]);
$carrier = carrier($om, quoter($om, stubGeocoder($tbilisi), $client, [], $cache));
check('Quickshipper failure → no rates, no exception', $carrier->collectRates(rateRequest($om)) === false);
check('failure not cached; next attempt asks again', $cache->data === [] && count(rates($carrier->collectRates(rateRequest($om)))) === 2);

$client = stubClient([]);
check('address not located → no rates, Quickshipper not asked',
    carrier($om, quoter($om, stubGeocoder(null), $client))->collectRates(rateRequest($om)) === false && $client->queries === []);

$g = stubGeocoder($tbilisi);
check('no pickup coordinates → no rates, nothing asked', carrier($om, quoter($om, $g, stubClient([]),
    [P . 'pickup_latitude' => '']))->collectRates(rateRequest($om)) === false && $g->calls === 0);

$g = stubGeocoder($tbilisi);
check('no Shipping Origin city or no store phone → no rates, nothing asked', carrier($om, quoter($om, $g, stubClient([]),
    ['shipping/origin/city' => '']))->collectRates(rateRequest($om)) === false
    && carrier($om, quoter($om, $g, stubClient([]), ['general/store_information/phone' => ' ']))
        ->collectRates(rateRequest($om)) === false && $g->calls === 0);
try {
    $cfg([P . 'pickup_latitude' => '', 'general/store_information/name' => ''] + PICKUP)->getPickupPoint();
    check('config: incomplete pickup point → one exception naming every missing part', false, 'no exception');
} catch (\Shubo\Qshpr\Exception\PickupNotConfiguredException $e) {
    check('config: incomplete pickup point → one exception naming every missing part',
        str_contains($e->getMessage(), 'missing: pickup latitude, store name.'), $e->getMessage());
}
$point = $cfg(PICKUP)->getPickupPoint();
check('config: complete pickup point', $point->latitude === 41.6934 && $point->street === 'Freedom Square 1'
    && $point->name === 'Check Shop' && $point->phone === '322000000');

$g = stubGeocoder($tbilisi);
check('store currency not GEL → no rates, nothing asked', carrier($om, quoter($om, $g, stubClient([]), ['currency/options/base' => 'USD']))
    ->collectRates(rateRequest($om)) === false && $g->calls === 0);

check('unexpected error → no rates, checkout not broken', carrier($om, quoter($om, stubGeocoder($tbilisi),
    stubClient([new \RuntimeException('boom')])))->collectRates(rateRequest($om)) === false);
check('allowed methods: the carrier itself', carrier($om, quoter($om, stubGeocoder(null), stubClient([])))
    ->getAllowedMethods() === ['shuboqshpr' => 'Quickshipper']);

// --- 2b. Courier logos ---
// Logo URLs as the sandbox sent them on 2026-10-02 (Wolt's has a raw space).
$withLogos = $feesAnswer;
$withLogos['fees'][0]['providerLogoUrl'] = 'https://test-static.quickshipper.app/apps/5c0d-image 12.png';
$withLogos['fees'][1]['providerLogoUrl'] = 'http://test-static.quickshipper.app/files/Thumb_fe8f.png';
$logoCache = memoryCache();
$logos = $om->create(CourierLogos::class, ['cache' => $logoCache]);
carrier($om, quoter($om, stubGeocoder($tbilisi), stubClient([$withLogos])), [], $logos)->collectRates(rateRequest($om));
check('logos: courier logo remembered, space encoded',
    $logos->get(2) === 'https://test-static.quickshipper.app/apps/5c0d-image%2012.png', $logos->get(2));
check('logos: plain-http logo ignored', $logos->get(25) === '');
check('logos: unknown courier → no logo', $logos->get(12) === '');
$logoKey = array_key_first($logoCache->data);
check('logos: kept 7 days with the rates tag', $logoCache->lifetimes[$logoKey] === 604800
    && in_array('SHUBO_QSHPR_RATES', $logoCache->tags[$logoKey], true));

$plugin = static fn (array $overrides): ShippingMethodLogo => $om->create(ShippingMethodLogo::class, [
    'courierLogos' => $logos,
    'config' => $om->create(Config::class, ['scopeConfig' => overrideScope($overrides, $om->get(ScopeConfigInterface::class))]),
]);
$method = static function (string $carrier, string $code) use ($om): ShippingMethodInterface {
    return $om->create(ShippingMethodInterface::class)->setCarrierCode($carrier)->setMethodCode($code);
};
$converter = $om->get(ShippingMethodConverter::class);
$rate = $om->create(Rate::class);
$logoOf = static fn (ShippingMethodInterface $m): ?string => $m->getExtensionAttributes()?->getShuboQshprLogoUrl();
check('logos: Wolt method gets its logo',
    $logoOf($plugin([])->afterModelToDataObject($converter, $method('shuboqshpr', '2_asap'), $rate))
        === 'https://test-static.quickshipper.app/apps/5c0d-image%2012.png');
check('logos: courier without a known logo → none',
    $logoOf($plugin([])->afterModelToDataObject($converter, $method('shuboqshpr', '25_85'), $rate)) === null);
check('logos: other carriers untouched',
    $logoOf($plugin([])->afterModelToDataObject($converter, $method('flatrate', '2_asap'), $rate)) === null);
check('logos: switched off → none',
    $logoOf($plugin([P . 'show_logos' => '0'])->afterModelToDataObject($converter, $method('shuboqshpr', '2_asap'), $rate)) === null);
check('config.xml: logos shown by default', (string)$defaults->show_logos === '1');

// --- 2c. Cash on delivery ---
$withCod = $feesAnswer;
$withCod['fees'][0]['hasCashOnDelivery'] = false;   // Wolt
$withCod['fees'][1]['hasCashOnDelivery'] = true;    // OnWay
$cod = $om->create(CashOnDelivery::class, ['cache' => memoryCache()]);
carrier($om, quoter($om, stubGeocoder($tbilisi), stubClient([$withCod])), [], cod: $cod)->collectRates(rateRequest($om));
check('cod: courier that collects cash remembered', $cod->courierCollectsCash(25));
check('cod: courier that does not → no', !$cod->courierCollectsCash(2));
check('cod: courier never quoted → no (safe default)', !$cod->courierCollectsCash(12));
check('cod: Magento\'s Cash On Delivery is a cash payment (di.xml)',
    $om->get(CashOnDelivery::class)->isCashPayment('cashondelivery') && !$om->get(CashOnDelivery::class)->isCashPayment('checkmo'));

$isAvailable = static function (string $paymentCode, string $shippingMethod) use ($om, $cod): bool {
    $quote = $om->create(\Magento\Quote\Model\Quote::class);
    $quote->getShippingAddress()->setShippingMethod($shippingMethod);
    $result = new \Magento\Framework\DataObject(['is_available' => true]);
    $method = $om->get(\Magento\Payment\Helper\Data::class)->getMethodInstance($paymentCode);
    $om->create(HideCashOnDelivery::class, ['cashOnDelivery' => $cod])->execute(new \Magento\Framework\Event\Observer([
        'event' => new \Magento\Framework\Event(['method_instance' => $method, 'result' => $result, 'quote' => $quote]),
    ]));

    return (bool)$result->getData('is_available');
};
check('cod: cash hidden for a courier that cannot collect it', !$isAvailable('cashondelivery', 'shuboqshpr_2_asap'));
check('cod: cash kept for a courier that collects it', $isAvailable('cashondelivery', 'shuboqshpr_25_85'));
check('cod: cash kept for other carriers', $isAvailable('cashondelivery', 'flatrate_flatrate'));
check('cod: other payments untouched', $isAvailable('checkmo', 'shuboqshpr_2_asap'));

// --- 2d. Car delivery ---
// Stands in for the merchant's own Yes/No attribute; the rule only reads it.
const CAR_ATTRIBUTE = 'qshpr_check_bulky';
$rule = static fn (array $o = []): CarDeliveryRule => $om->create(CarDeliveryRule::class, [
    'config' => $om->create(Config::class, ['scopeConfig' => overrideScope($o, $om->get(ScopeConfigInterface::class))]),
]);
$attr = [P . 'car_delivery_attribute' => CAR_ATTRIBUTE];
$def = [P . 'car_delivery_default' => '1'];
check('car: any item Yes → car', $rule()->decide([null, '0', '1'], 1, 1.0));
check('car: no item Yes, default off → no car', !$rule()->decide([null, '0', ''], 1, 1.0));
check('car: default on → car', $rule($def)->decide(['0'], 1, 1.0) && $rule($def)->decide([], 1, 0.0));
check('car: from-weight reached → car', $rule([P . 'car_delivery_from_weight' => '8'])->decide(['0'], 1, 8.0));
check('car: under the from-weight → no car', !$rule([P . 'car_delivery_from_weight' => '8'])->decide([], 1, 7.9));
check('car: from-weight empty or 0 → not used', !$rule([P . 'car_delivery_from_weight' => ''])->decide([], 1, 50.0)
    && !$rule([P . 'car_delivery_from_weight' => '0'])->decide([], 1, 50.0));
check('config.xml: car delivery off by default, no attribute chosen',
    (string)$defaults->car_delivery_default === '0' && (string)$defaults->car_delivery_attribute === '');

$cartItem = static function (mixed $value, string $type = 'simple') use ($om) {
    $product = $om->create(\Magento\Catalog\Model\Product::class)->setTypeId($type)->setData(CAR_ATTRIBUTE, $value);

    return $om->create(\Magento\Quote\Model\Quote\Item::class)->setProduct($product);
};
check('car: cart item with the attribute Yes → car', $rule($attr)->forRateRequest(
    rateRequest($om, ['all_items' => [$cartItem(null), $cartItem('1')]]), 1, 1.0));
check('car: Yes on a virtual item does not count', !$rule($attr)->forRateRequest(
    rateRequest($om, ['all_items' => [$cartItem(null), $cartItem(1, 'virtual')]]), 1, 1.0));
check('car: no attribute chosen → product values ignored', !$rule()->forRateRequest(
    rateRequest($om, ['all_items' => [$cartItem('1')]]), 1, 1.0));

$quoteConfig = $om->get(\Magento\Quote\Model\Quote\Config::class);
$loaded = static fn (array $o): array => $om->create(QuoteItemCarDeliveryAttribute::class, [
    'config' => $om->create(Config::class, ['scopeConfig' => overrideScope($o, $om->get(ScopeConfigInterface::class))]),
])->afterGetProductAttributes($quoteConfig, ['sku', 'weight']);
check('car: chosen attribute loaded with cart items', $loaded($attr) === ['sku', 'weight', CAR_ATTRIBUTE]);
check('car: none chosen → cart item attributes unchanged', $loaded([]) === ['sku', 'weight']);
$values = array_column($om->create(YesNoProductAttribute::class)->toOptionArray(), 'value');
$connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$ownYesNo = $connection->fetchCol($connection->select()
    ->from($connection->getTableName('eav_attribute'), ['attribute_code'])
    ->where('entity_type_id = ?', (int)$om->get(\Magento\Eav\Model\Config::class)->getEntityType('catalog_product')->getId())
    ->where('frontend_input = ?', 'boolean')
    ->where('is_user_defined = ?', 1));
sort($ownYesNo);
$listed = array_slice($values, 1);
sort($listed);
check('car: settings list = "Not used" + the store\'s own Yes/No attributes (no bundle switches)',
    $values[0] === '' && $listed === $ownYesNo && !in_array('price_type', $values, true), implode(', ', $listed));

$client = stubClient([$feesAnswer]);
carrier($om, quoter($om, stubGeocoder($tbilisi), $client, $def))->collectRates(rateRequest($om, ['all_items' => [$cartItem(null)]]));
check('car: fees query asks CarDelivery=true (as text)', ($client->queries[0]['CarDelivery'] ?? null) === 'true');
$client = stubClient([$feesAnswer]);
carrier($om, quoter($om, stubGeocoder($tbilisi), $client))->collectRates(rateRequest($om, ['all_items' => [$cartItem(null)]]));
check('car: no car → no CarDelivery in the query', !array_key_exists('CarDelivery', $client->queries[0]));

// --- 2e. Delivery times ---
// Which couriers take a time comes only from Quickshipper's hasScheduledDelivery,
// never from the price id: here Glovo's "ASAP" says no, OnWay's "85" says yes.
$withSchedule = $feesAnswer;
$withSchedule['fees'][0]['hasScheduledDelivery'] = true;    // Wolt
$withSchedule['fees'][1]['hasScheduledDelivery'] = true;    // OnWay
$withSchedule['fees'][2]['hasScheduledDelivery'] = false;   // Glovo
$withSchedule['fees'][2]['isActive'] = true;           // offered, so its flag is read
$scheduled = $om->create(ScheduledCouriers::class, ['cache' => memoryCache()]);
carrier($om, quoter($om, stubGeocoder($tbilisi), stubClient([$withSchedule])), [], scheduled: $scheduled)
    ->collectRates(rateRequest($om));
check('time: couriers Quickshipper says take a time remembered', $scheduled->takesTime(2) && $scheduled->takesTime(25));
check('time: courier that does not → no', !$scheduled->takesTime(12));
$noFlag = $om->create(ScheduledCouriers::class, ['cache' => memoryCache()]);
carrier($om, quoter($om, stubGeocoder($tbilisi), stubClient([$feesAnswer])), [], scheduled: $noFlag)
    ->collectRates(rateRequest($om));
check('time: flag missing or never quoted → no (safe default)', !$noFlag->takesTime(2) && !$noFlag->takesTime(99));

$slotsWith = static function (array $o) use ($om, $scheduled): DeliverySlots {
    $scope = overrideScope($o + [P . 'schedule/enabled' => '1', 'general/locale/timezone' => 'Asia/Tbilisi'],
        $om->get(ScopeConfigInterface::class));

    return $om->create(DeliverySlots::class, [
        'config' => $om->create(Config::class, ['scopeConfig' => $scope]),
        'timezone' => $om->create(\Magento\Framework\Stdlib\DateTime\Timezone::class, ['scopeConfig' => $scope]),
        'scheduledCouriers' => $scheduled,
    ]);
};
$early = new \DateTimeImmutable('2026-10-03T01:40:00Z');   // 05:40 in Tbilisi
$slots = $slotsWith([])->getSlots(1, $early);
check('time: defaults → today + 2 days, 10:00–20:00 in 2-hour slots', count($slots) === 15
    && $slots[0] === ['value' => '2026-10-03T06:00:00Z', 'label' => 'Sat, 3 Oct, 10:00–12:00']
    && end($slots)['label'] === 'Mon, 5 Oct, 18:00–20:00', json_encode([$slots[0], end($slots)]));
$late = new \DateTimeImmutable('2026-10-03T13:30:00Z');    // 17:30 in Tbilisi
check('time: 2 hours\' notice → no slot today after 17:30', count($slotsWith([])->getSlots(1, $late)) === 10
    && $slotsWith([])->getSlots(1, $late)[0]['label'] === 'Sun, 4 Oct, 10:00–12:00');
check('time: settings followed (1 day, 9–13, 1-hour slots)', array_column($slotsWith([P . 'schedule/days' => '1',
    P . 'schedule/first_hour' => '9', P . 'schedule/last_hour' => '13', P . 'schedule/slot_hours' => '1'])
    ->getSlots(1, $early), 'label')[0] === 'Sat, 3 Oct, 09:00–10:00');
check('time: switched off → no slots', $slotsWith([P . 'schedule/enabled' => '0'])->getSlots(1, $early) === []);
check('time: follows the courier\'s flag, not the price id', $slotsWith([])->acceptsTime('shuboqshpr_2_asap')
    && !$slotsWith([])->acceptsTime('shuboqshpr_12_asap') && $slotsWith([])->acceptsTime('shuboqshpr_25_85')
    && !$slotsWith([])->acceptsTime('flatrate_flatrate'));
$timePlugin = $om->create(ShippingMethodDeliveryTime::class, ['deliverySlots' => $slotsWith([])]);
$takesTimeOf = static fn (ShippingMethodInterface $m): ?bool => $m->getExtensionAttributes()?->getShuboQshprTakesTime();
check('time: checkout marks the methods that take a time', $takesTimeOf($timePlugin->afterModelToDataObject(
    $converter, $method('shuboqshpr', '2_asap'), $rate)) === true
    && $takesTimeOf($timePlugin->afterModelToDataObject($converter, $method('shuboqshpr', '25_85'), $rate)) === true);
check('time: no mark for a courier that does not, nor other carriers', $takesTimeOf($timePlugin->afterModelToDataObject(
    $converter, $method('shuboqshpr', '12_asap'), $rate)) === null
    && $takesTimeOf($timePlugin->afterModelToDataObject($converter, $method('flatrate', 'flatrate'), $rate)) === null);
check('time: any chosen time described in store time', $slotsWith([])->describe('2026-10-04T08:00:00Z', 1)
    === 'Sun, 4 Oct, 12:00–14:00');

$offered = $slotsWith([])->getSlots(1)[0]['value'] ?? '';
$saveWith = static function (string $method, string $time) use ($om, $slotsWith): array {
    $info = $om->create(\Magento\Checkout\Api\Data\ShippingInformationInterface::class);
    $address = $om->create(\Magento\Quote\Model\Quote\Address::class);
    [$carrier, $code] = explode('_', $method, 2);
    $info->setShippingAddress($address)->setShippingCarrierCode($carrier)->setShippingMethodCode($code);
    $extension = $om->get(\Magento\Framework\Api\ExtensionAttributesFactory::class)
        ->create(\Magento\Checkout\Api\Data\ShippingInformationInterface::class);
    $extension->setShuboQshprDeliveryTime($time);
    $info->setExtensionAttributes($extension);
    $carts = new class ($om->create(\Magento\Quote\Model\Quote::class)->setStoreId(1)) implements \Magento\Quote\Api\CartRepositoryInterface {
        public function __construct(private $quote)
        {
        }

        public function get($cartId)
        {
            return $this->quote;
        }

        public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria)
        {
            throw new \RuntimeException('not used');
        }

        public function getForCustomer($customerId, array $sharedStoreIds = [])
        {
            throw new \RuntimeException('not used');
        }

        public function getActive($cartId, array $sharedStoreIds = [])
        {
            return $this->quote;
        }

        public function getActiveForCustomer($customerId, array $sharedStoreIds = [])
        {
            throw new \RuntimeException('not used');
        }

        public function save(\Magento\Quote\Api\Data\CartInterface $quote)
        {
        }

        public function delete(\Magento\Quote\Api\Data\CartInterface $quote)
        {
        }
    };
    $plugin = $om->create(SaveDeliveryTime::class, ['deliverySlots' => $slotsWith([]), 'cartRepository' => $carts]);
    try {
        $plugin->beforeSaveAddressInformation($om->get(\Magento\Checkout\Model\ShippingInformationManagement::class), 1, $info);
        $error = '';
    } catch (\Magento\Framework\Exception\InputException $e) {
        $error = $e->getMessage();
    }

    return [$address->getData(SaveDeliveryTime::ADDRESS_FIELD), $error];
};
check('save: an offered slot for a courier that takes a time is kept', $offered !== ''
    && $saveWith('shuboqshpr_2_asap', $offered) === [$offered, '']
    && $saveWith('shuboqshpr_25_85', $offered) === [$offered, '']);
check('save: a time not offered is refused', str_contains($saveWith('shuboqshpr_2_asap', '2020-01-01T08:00:00Z')[1], 'no longer available'));
check('save: other deliveries clear the time', $saveWith('shuboqshpr_12_asap', $offered) === [null, '']
    && $saveWith('flatrate_flatrate', $offered) === [null, '']);
check('save: "as soon as possible" clears the time', $saveWith('shuboqshpr_2_asap', '') === [null, '']);

// --- 3. LIVE sandbox ---
[$liveUser, $livePassword] = sandboxCredentials();
if (isOffline()) {
    echo "SKIP  live section: QSHPR_OFFLINE=1\n";
} else {
    check('live: sandbox credentials available (env or .qshpr.env)', $liveUser !== '' && $livePassword !== '');
}

if ($liveUser !== '' && $livePassword !== '') {
    $liveOverrides = [
        P . 'username' => $liveUser,
        P . 'password' => $om->get(EncryptorInterface::class)->encrypt($livePassword),
        P . 'sandbox_mode' => '1',
        // The log assertions below are about normal logging, whatever the store has set.
        P . 'debug' => '0',
    ] + PICKUP;
    $liveConfig = $om->create(Config::class, ['scopeConfig' => overrideScope($liveOverrides, $realScope)]);
    $liveClient = $om->create(QshprClient::class, [
        'config' => $liveConfig,
        'tokenCache' => $om->create(TokenCache::class, ['config' => $liveConfig, 'cache' => memoryCache()]),
    ]);
    $liveGeocoder = $om->create(Geocoder::class, ['config' => $liveConfig, 'cache' => memoryCache()]);
    $liveCarrier = carrier($om, $om->create(RateQuoter::class, [
        'geocoder' => $liveGeocoder,
        'client' => $liveClient,
        'config' => $liveConfig,
        'weightConverter' => $om->create(WeightConverter::class, ['config' => $liveConfig]),
        'cache' => memoryCache(),
    ]));

    $live = rates($liveCarrier->collectRates(rateRequest($om, ['package_weight' => 1.0])));
    check('live: Tbilisi address → courier rates in GEL', $live !== [] && min(array_column($live, 'price')) > 0,
        implode('; ', array_map(static fn ($r) => $r['title'] . ' ' . $r['price'], $live)) ?: 'none (if a timeout is logged, rerun: the sandbox is slow after a quiet period)');

    usleep(1100000);  // public Nominatim: at most 1 request per second
    $batumi = $liveCarrier->collectRates(rateRequest($om, [
        'dest_street' => 'ქ. ბათუმი, ვაჟა-ფშაველას ქ. №33',
        'dest_city' => 'ბათუმი',
    ]));
    check('live: Batumi address (intercity from Tbilisi) → no rates', $batumi === false);
}

// --- 4. Log ---
$log = substr((string)file_get_contents($logFile), $logOffset);
check('log: incomplete pickup point logged', str_contains($log, 'rates: Quickshipper not offered: The Quickshipper pickup point is incomplete, missing: pickup latitude.'));
check('log: non-GEL store logged', str_contains($log, 'base currency USD is not GEL'));
check('log: unexpected error logged as critical', str_contains($log, 'shubo_qshpr.CRITICAL: rates: unexpected failure: boom'));
check('log: no customer address in the log', !str_contains($log, 'კარგარეთ'));

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
