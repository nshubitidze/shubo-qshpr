<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Shubo\Qshpr\Exception\PickupNotConfiguredException;
use Shubo\Qshpr\Exception\QshprApiException;
use Shubo\Qshpr\Model\Ui\ConfigProvider;

/**
 * Typed access to carriers/shuboqshpr/* configuration — the single place that
 * knows field names, casts, environment selection and decryption of secrets.
 */
class Config
{
    private const string PATH_PREFIX = 'carriers/' . ConfigProvider::CODE . '/';

    private const string FIELD_SANDBOX_MODE = 'sandbox_mode';
    private const string FIELD_SANDBOX_AUTH_URL = 'sandbox_auth_url';
    private const string FIELD_SANDBOX_API_URL = 'sandbox_api_url';
    private const string FIELD_PRODUCTION_AUTH_URL = 'production_auth_url';
    private const string FIELD_PRODUCTION_API_URL = 'production_api_url';
    private const string FIELD_SANDBOX_DASHBOARD_URL = 'sandbox_dashboard_url';
    private const string FIELD_PRODUCTION_DASHBOARD_URL = 'production_dashboard_url';
    private const string FIELD_API_TIMEOUT = 'api_timeout';
    private const string FIELD_DEBUG = 'debug';
    private const string FIELD_SHOW_BALANCE = 'show_balance';
    private const string FIELD_USERNAME = 'username';
    private const string FIELD_PASSWORD = 'password';
    private const string FIELD_SANDBOX_CLIENT_ID = 'sandbox_client_id';
    private const string FIELD_SANDBOX_CLIENT_SECRET = 'sandbox_client_secret';
    private const string FIELD_PRODUCTION_CLIENT_ID = 'production_client_id';
    private const string FIELD_PRODUCTION_CLIENT_SECRET = 'production_client_secret';

    /**
     * Quickshipper's standard OAuth client: public (its API docs), the same
     * for every shop, and — Quickshipper says — currently also production's.
     * Used for production while its admin fields are empty.
     */
    private const string STANDARD_CLIENT_ID = 'DeliveryApiClient';
    private const string STANDARD_CLIENT_SECRET = 'DeliveryApiSecret';
    private const string FIELD_AUTO_DISPATCH = 'auto_dispatch';
    private const string FIELD_CANCEL_ON_REFUND = 'cancel_on_refund';
    private const string FIELD_SHOW_LOGOS = 'show_logos';
    private const string FIELD_CUSTOMER_PAYS_SERVICE_FEE = 'customer_pays_service_fee';
    private const string FIELD_CAR_DELIVERY_ATTRIBUTE = 'car_delivery_attribute';
    private const string FIELD_CAR_DELIVERY_DEFAULT = 'car_delivery_default';
    private const string FIELD_CAR_DELIVERY_FROM_WEIGHT = 'car_delivery_from_weight';
    private const string FIELD_SCHEDULE_ENABLED = 'schedule/enabled';
    private const string FIELD_SCHEDULE_DAYS = 'schedule/days';
    private const string FIELD_SCHEDULE_FIRST_HOUR = 'schedule/first_hour';
    private const string FIELD_SCHEDULE_LAST_HOUR = 'schedule/last_hour';
    private const string FIELD_SCHEDULE_SLOT_HOURS = 'schedule/slot_hours';
    private const string FIELD_SCHEDULE_NOTICE_HOURS = 'schedule/notice_hours';
    private const string FIELD_PICKUP_LATITUDE = 'pickup_latitude';
    private const string FIELD_PICKUP_LONGITUDE = 'pickup_longitude';
    private const string GEOCODING_GROUP = 'geocoding/';
    private const string FIELD_GEOCODER = self::GEOCODING_GROUP . 'geocoder';
    private const string GEOCODER_URL_SUFFIX = '_url';
    private const string FIELD_ADDRESS_CLEAN_UP = self::GEOCODING_GROUP . 'address_clean_up';
    private const string FIELD_WEBHOOK_KEY = 'webhook_key';

    /**
     * Magento's own settings this module reads: the Shipping Origin address
     * (the pickup point's text), Store Information name and phone (the pickup
     * contact the courier calls) and the store's weight unit ("lbs" or "kgs").
     */
    private const string XML_PATH_ORIGIN_STREET = 'shipping/origin/street_line1';
    private const string XML_PATH_ORIGIN_CITY = 'shipping/origin/city';
    private const string XML_PATH_STORE_NAME = 'general/store_information/name';
    private const string XML_PATH_STORE_PHONE = 'general/store_information/phone';
    private const string XML_PATH_WEIGHT_UNIT = 'general/locale/weight_unit';
    private const string XML_PATH_BASE_CURRENCY = 'currency/options/base';

    private const float MAX_LATITUDE = 90.0;
    private const float MAX_LONGITUDE = 180.0;

    /**
     * Guard against a missing/zero api_timeout: curl treats 0 as "no
     * timeout", which would let a checkout rate request hang forever.
     * Quickshipper usually answers a rate request in 0.3–2 s, but its first
     * answer after a quiet period has taken more than 10 s, and confirming
     * a booking takes 7–10 s.
     */
    private const int DEFAULT_TIMEOUT_SECONDS = 20;

    /**
     * Scheduled delivery fallbacks for missing or zero settings: tomorrow and
     * the day after, 10:00 to 20:00 in two-hour slots, two hours' notice.
     */
    private const int DEFAULT_SCHEDULE_DAYS = 2;
    private const int DEFAULT_SCHEDULE_FIRST_HOUR = 10;
    private const int DEFAULT_SCHEDULE_LAST_HOUR = 20;
    private const int DEFAULT_SCHEDULE_SLOT_HOURS = 2;
    private const int DEFAULT_SCHEDULE_NOTICE_HOURS = 2;
    private const int HOURS_PER_DAY = 24;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    /**
     * Whether the sandbox (test) environment is used instead of production.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSandbox(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_SANDBOX_MODE, $storeId);
    }

    /**
     * OAuth host base URL for the active environment, without a trailing slash.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getAuthUrl(?int $storeId = null): string
    {
        $field = $this->isSandbox($storeId) ? self::FIELD_SANDBOX_AUTH_URL : self::FIELD_PRODUCTION_AUTH_URL;

        return rtrim((string)$this->getValue($field, $storeId), '/');
    }

    /**
     * Delivery API base URL for the active environment, without a trailing slash.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiUrl(?int $storeId = null): string
    {
        $field = $this->isSandbox($storeId) ? self::FIELD_SANDBOX_API_URL : self::FIELD_PRODUCTION_API_URL;

        return rtrim((string)$this->getValue($field, $storeId), '/');
    }

    /**
     * The merchant's Quickshipper dashboard for the active environment, without a trailing slash.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getDashboardUrl(?int $storeId = null): string
    {
        $field = $this->isSandbox($storeId) ? self::FIELD_SANDBOX_DASHBOARD_URL : self::FIELD_PRODUCTION_DASHBOARD_URL;

        return rtrim((string)$this->getValue($field, $storeId), '/');
    }

    /**
     * HTTP timeout for Quickshipper calls, in seconds (never 0).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getApiTimeout(?int $storeId = null): int
    {
        $timeout = (int)$this->getValue(self::FIELD_API_TIMEOUT, $storeId);

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * Whether full masked payload dumps are logged (carriers/shuboqshpr/debug).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDebugEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_DEBUG, $storeId);
    }

    /**
     * Whether the admin header shows the Quickshipper balance (default scope: the header has none).
     *
     * @return bool
     */
    public function isBalanceShown(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::PATH_PREFIX . self::FIELD_SHOW_BALANCE);
    }

    /**
     * The merchant's Quickshipper dashboard login (OAuth password grant).
     *
     * @param int|null $storeId
     * @param bool $strict Throw instead of returning an unconfigured (empty) value.
     * @return string
     * @throws QshprApiException
     */
    public function getUsername(?int $storeId = null, bool $strict = false): string
    {
        $username = trim((string)$this->getValue(self::FIELD_USERNAME, $storeId));

        if ($strict && $username === '') {
            throw new QshprApiException(
                __('Quickshipper login email is not configured for store %1.', $storeId ?? 'default')
            );
        }

        return $username;
    }

    /**
     * The merchant's Quickshipper dashboard password, decrypted.
     *
     * @param int|null $storeId
     * @param bool $strict Throw instead of returning an unconfigured (empty) value.
     * @return string
     * @throws QshprApiException
     */
    public function getPassword(?int $storeId = null, bool $strict = false): string
    {
        $password = $this->encryptor->decrypt((string)$this->getValue(self::FIELD_PASSWORD, $storeId));

        if ($strict && $password === '') {
            throw new QshprApiException(
                __('Quickshipper password is not configured for store %1.', $storeId ?? 'default')
            );
        }

        return $password;
    }

    /**
     * OAuth client id for the active environment. The sandbox client is the
     * public one from Quickshipper's docs (config.xml); production uses the
     * same standard client unless the admin field holds another one.
     *
     * @param int|null $storeId
     * @param bool $strict Throw instead of returning an unconfigured (empty) value.
     * @return string
     * @throws QshprApiException
     */
    public function getClientId(?int $storeId = null, bool $strict = false): string
    {
        $clientId = $this->isSandbox($storeId)
            ? trim((string)$this->getValue(self::FIELD_SANDBOX_CLIENT_ID, $storeId))
            : (trim((string)$this->getValue(self::FIELD_PRODUCTION_CLIENT_ID, $storeId)) ?: self::STANDARD_CLIENT_ID);

        if ($strict && $clientId === '') {
            throw new QshprApiException(
                __('Quickshipper API client ID is not configured for store %1.', $storeId ?? 'default')
            );
        }

        return $clientId;
    }

    /**
     * OAuth client secret for the active environment.
     *
     * Production: decrypted from the admin field, or the standard client's
     * while that field is empty.
     *
     * @param int|null $storeId
     * @param bool $strict Throw instead of returning an unconfigured (empty) value.
     * @return string
     * @throws QshprApiException
     */
    public function getClientSecret(?int $storeId = null, bool $strict = false): string
    {
        $secret = $this->isSandbox($storeId)
            ? (string)$this->getValue(self::FIELD_SANDBOX_CLIENT_SECRET, $storeId)
            : ($this->encryptor->decrypt((string)$this->getValue(self::FIELD_PRODUCTION_CLIENT_SECRET, $storeId))
                ?: self::STANDARD_CLIENT_SECRET);

        if ($strict && $secret === '') {
            throw new QshprApiException(
                __('Quickshipper API client secret is not configured for store %1.', $storeId ?? 'default')
            );
        }

        return $secret;
    }

    /**
     * Whether a paid order books its courier and ships without the admin's Ship button.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAutoDispatchEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_AUTO_DISPATCH, $storeId);
    }

    /**
     * Whether a full refund cancels the order's Quickshipper deliveries still under way.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCancelOnRefundEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_CANCEL_ON_REFUND, $storeId);
    }

    /**
     * Whether checkout shows each courier's logo beside its shipping method.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function areCourierLogosShown(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_SHOW_LOGOS, $storeId);
    }

    /**
     * Whether the checkout price includes Quickshipper's service fee, or the shop pays it.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isServiceFeeChargedToCustomer(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_CUSTOMER_PAYS_SERVICE_FEE, $storeId);
    }

    /**
     * Code of the Yes/No product attribute that marks products needing a car; '' when not used.
     *
     * Default scope only: product attributes are the same for every website.
     *
     * @return string
     */
    public function getCarDeliveryAttribute(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::PATH_PREFIX . self::FIELD_CAR_DELIVERY_ATTRIBUTE));
    }

    /**
     * Whether every Quickshipper delivery asks for a courier with a car.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCarDeliveryDefault(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_CAR_DELIVERY_DEFAULT, $storeId);
    }

    /**
     * Parcel weight in kg from which a car is always asked for; null when not set (or zero).
     *
     * @param int|null $storeId
     * @return float|null
     */
    public function getCarDeliveryFromWeightKg(?int $storeId = null): ?float
    {
        $value = trim((string)$this->getValue(self::FIELD_CAR_DELIVERY_FROM_WEIGHT, $storeId));

        return is_numeric($value) && (float)$value > 0 ? (float)$value : null;
    }

    /**
     * Whether customers may choose a delivery time for couriers that take one.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isScheduleEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_SCHEDULE_ENABLED, $storeId);
    }

    /**
     * How many days ahead a delivery time can be chosen (today counts as day 0).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getScheduleDays(?int $storeId = null): int
    {
        $days = (int)$this->getValue(self::FIELD_SCHEDULE_DAYS, $storeId);

        return $days > 0 ? $days : self::DEFAULT_SCHEDULE_DAYS;
    }

    /**
     * Hour (store time) the first delivery slot of a day starts.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getScheduleFirstHour(?int $storeId = null): int
    {
        $hour = (int)$this->getValue(self::FIELD_SCHEDULE_FIRST_HOUR, $storeId);

        return $hour > 0 && $hour < self::HOURS_PER_DAY ? $hour : self::DEFAULT_SCHEDULE_FIRST_HOUR;
    }

    /**
     * Hour (store time) the last delivery slot of a day ends.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getScheduleLastHour(?int $storeId = null): int
    {
        $hour = (int)$this->getValue(self::FIELD_SCHEDULE_LAST_HOUR, $storeId);

        return $hour > 0 && $hour <= self::HOURS_PER_DAY ? $hour : self::DEFAULT_SCHEDULE_LAST_HOUR;
    }

    /**
     * Length of one delivery slot in hours.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getScheduleSlotHours(?int $storeId = null): int
    {
        $hours = (int)$this->getValue(self::FIELD_SCHEDULE_SLOT_HOURS, $storeId);

        return $hours > 0 ? $hours : self::DEFAULT_SCHEDULE_SLOT_HOURS;
    }

    /**
     * Hours between placing the order and the earliest slot that can be chosen.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getScheduleNoticeHours(?int $storeId = null): int
    {
        $hours = (int)$this->getValue(self::FIELD_SCHEDULE_NOTICE_HOURS, $storeId);

        return $hours > 0 ? $hours : self::DEFAULT_SCHEDULE_NOTICE_HOURS;
    }

    /**
     * Latitude of the shop's pickup point; null when not set, zero or not a valid latitude.
     *
     * @param int|null $storeId
     * @return float|null
     */
    public function getPickupLatitude(?int $storeId = null): ?float
    {
        return $this->coordinate($this->getValue(self::FIELD_PICKUP_LATITUDE, $storeId), self::MAX_LATITUDE);
    }

    /**
     * Longitude of the shop's pickup point; null when not set, zero or not a valid longitude.
     *
     * @param int|null $storeId
     * @return float|null
     */
    public function getPickupLongitude(?int $storeId = null): ?float
    {
        return $this->coordinate($this->getValue(self::FIELD_PICKUP_LONGITUDE, $storeId), self::MAX_LONGITUDE);
    }

    /**
     * Where the courier collects; every part is required by Quickshipper.
     *
     * Coordinates come from these settings, the address from Magento's
     * Shipping Origin, name and phone from Store Information.
     *
     * @param int|null $storeId
     * @return PickupPoint
     * @throws PickupNotConfiguredException Naming every missing part.
     */
    public function getPickupPoint(?int $storeId = null): PickupPoint
    {
        $latitude = $this->getPickupLatitude($storeId);
        $longitude = $this->getPickupLongitude($storeId);
        $street = $this->getOriginStreet($storeId);
        $city = $this->getOriginCity($storeId);
        $name = $this->getStoreName($storeId);
        $phone = $this->getStorePhone($storeId);
        $missing = array_keys(array_filter([
            (string)__('pickup latitude') => $latitude === null,
            (string)__('pickup longitude') => $longitude === null,
            (string)__('Shipping Origin street') => $street === '',
            (string)__('Shipping Origin city') => $city === '',
            (string)__('store name') => $name === '',
            (string)__('store phone') => $phone === '',
        ]));

        if ($missing !== []) {
            throw new PickupNotConfiguredException(__(
                'The Quickshipper pickup point is incomplete, missing: %1. Set the pickup coordinates in '
                . 'Stores → Configuration → Sales → Delivery Methods → Quickshipper, the street and city in '
                . 'Sales → Shipping Settings → Origin, and the name and phone in General → Store Information.',
                implode(', ', $missing)
            ));
        }

        return new PickupPoint($latitude, $longitude, $street, $city, $name, $phone);
    }

    /**
     * Code of the geocoder picked in the admin (a provider key in di.xml).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getGeocoder(?int $storeId = null): string
    {
        return trim((string)$this->getValue(self::FIELD_GEOCODER, $storeId));
    }

    /**
     * Whether the geocoder gets the cleaned-up search text (on by default) or the address as typed.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAddressCleanUpEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_ADDRESS_CLEAN_UP, $storeId);
    }

    /**
     * Server URL of the geocoder picked in the admin, without a trailing slash.
     *
     * Every geocoder keeps its URL at geocoding/<code>_url (Nominatim:
     * geocoding/nominatim_url), so adding or removing a geocoder never
     * changes this class.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getGeocoderUrl(?int $storeId = null): string
    {
        $field = self::GEOCODING_GROUP . $this->getGeocoder($storeId) . self::GEOCODER_URL_SUFFIX;

        return rtrim(trim((string)$this->getValue($field, $storeId)), '/');
    }

    /**
     * Street line of Magento's Shipping Origin (the pickup address text).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getOriginStreet(?int $storeId = null): string
    {
        return trim((string)$this->getStoreValue(self::XML_PATH_ORIGIN_STREET, $storeId));
    }

    /**
     * City of Magento's Shipping Origin.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getOriginCity(?int $storeId = null): string
    {
        return trim((string)$this->getStoreValue(self::XML_PATH_ORIGIN_CITY, $storeId));
    }

    /**
     * Store name from Store Information — who the courier collects from.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStoreName(?int $storeId = null): string
    {
        return trim((string)$this->getStoreValue(self::XML_PATH_STORE_NAME, $storeId));
    }

    /**
     * Store phone from Store Information — the number the courier calls at pickup.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getStorePhone(?int $storeId = null): string
    {
        return trim((string)$this->getStoreValue(self::XML_PATH_STORE_PHONE, $storeId));
    }

    /**
     * The store's weight unit: "lbs" or "kgs".
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWeightUnit(?int $storeId = null): string
    {
        return (string)$this->getStoreValue(self::XML_PATH_WEIGHT_UNIT, $storeId);
    }

    /**
     * The store's base currency code, e.g. "GEL" — the currency rates are quoted in.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getBaseCurrency(?int $storeId = null): string
    {
        return strtoupper(trim((string)$this->getStoreValue(self::XML_PATH_BASE_CURRENCY, $storeId)));
    }

    /**
     * Secret in the status webhook's URL (set by "Connect status updates"); '' when not set.
     *
     * Quickshipper does not sign webhook calls, so the key only keeps
     * strangers from making the store call Quickshipper; it proves nothing
     * about the sender. Kept at the default scope: one key per installation.
     *
     * @return string
     */
    public function getWebhookKey(): string
    {
        $stored = (string)$this->scopeConfig->getValue($this->getWebhookKeyPath());

        return $stored === '' ? '' : $this->encryptor->decrypt($stored);
    }

    /**
     * Config path of the webhook key, for the code that stores a new one.
     *
     * @return string
     */
    public function getWebhookKeyPath(): string
    {
        return self::PATH_PREFIX . self::FIELD_WEBHOOK_KEY;
    }

    /**
     * A configured coordinate as a float within ±$max, or null.
     *
     * Zero counts as not set: a pickup point at 0 would be in the Gulf of
     * Guinea, and an empty admin field is easily saved as 0.
     *
     * @param mixed $value
     * @param float $max
     * @return float|null
     */
    private function coordinate(mixed $value, float $max): ?float
    {
        $value = is_string($value) ? trim($value) : $value;

        if (!is_numeric($value)) {
            return null;
        }

        $coordinate = (float)$value;

        return $coordinate !== 0.0 && abs($coordinate) <= $max ? $coordinate : null;
    }

    /**
     * Raw store-scoped value of any config path.
     *
     * @param string $path
     * @param int|null $storeId
     * @return mixed
     */
    private function getStoreValue(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Raw store-scoped value of one carriers/shuboqshpr/* field.
     *
     * @param string $field
     * @param int|null $storeId
     * @return mixed
     */
    private function getValue(string $field, ?int $storeId): mixed
    {
        return $this->getStoreValue(self::PATH_PREFIX . $field, $storeId);
    }
}
