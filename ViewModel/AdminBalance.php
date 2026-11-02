<?php

declare(strict_types=1);

namespace Shubo\Qshpr\ViewModel;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Shubo\Qshpr\Model\Account\BalanceReader;
use Shubo\Qshpr\Model\Carrier\Config;

/**
 * The Quickshipper balance in the admin header: whether it is shown, and for
 * which account.
 *
 * The header belongs to no website, so it shows the account of the default
 * store view — the login saved at the default scope unless that website has
 * its own.
 */
class AdminBalance implements ArgumentInterface
{
    /** Admins allowed to create shipments, i.e. to book couriers. */
    public const string ACL_RESOURCE = 'Magento_Sales::shipment';

    /** Admins allowed to change the Delivery Methods settings. */
    private const string SETTINGS_ACL_RESOURCE = 'Magento_Shipping::carriers';

    private const string BALANCE_ROUTE = 'shuboqshpr/account/balance';
    private const string SETTINGS_ROUTE = 'adminhtml/system_config/edit/section/carriers';

    /**
     * @param Config $config
     * @param BalanceReader $balanceReader
     * @param AuthorizationInterface $authorization
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $url Backend URL builder (adminhtml area).
     */
    public function __construct(
        private readonly Config $config,
        private readonly BalanceReader $balanceReader,
        private readonly AuthorizationInterface $authorization,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $url,
    ) {
    }

    /**
     * Whether the header shows the balance: switched on, a login saved, and allowed for this admin.
     *
     * @return bool
     */
    public function isVisible(): bool
    {
        $storeId = $this->getStoreId();

        return $this->config->isBalanceShown()
            && $this->config->getUsername($storeId) !== ''
            && $this->authorization->isAllowed(self::ACL_RESOURCE);
    }

    /**
     * Whether the account shown is Quickshipper's test environment.
     *
     * @return bool
     */
    public function isSandbox(): bool
    {
        return $this->config->isSandbox($this->getStoreId());
    }

    /**
     * The balance text, or null when it cannot be read.
     *
     * @return string|null
     */
    public function getBalance(): ?string
    {
        return $this->balanceReader->getBalance($this->getStoreId());
    }

    /**
     * The Quickshipper dashboard of the account shown, where the balance is topped up.
     *
     * @return string
     */
    public function getDashboardUrl(): string
    {
        return $this->config->getDashboardUrl($this->getStoreId());
    }

    /**
     * The module's settings page, or null when this admin may not open it.
     *
     * @return string|null
     */
    public function getSettingsUrl(): ?string
    {
        return $this->authorization->isAllowed(self::SETTINGS_ACL_RESOURCE)
            ? $this->url->getUrl(self::SETTINGS_ROUTE)
            : null;
    }

    /**
     * Where the header fetches the balance from.
     *
     * @return string
     */
    public function getBalanceUrl(): string
    {
        return $this->url->getUrl(self::BALANCE_ROUTE);
    }

    /**
     * The default store view's id, whose configuration the header uses.
     *
     * @return int|null
     */
    private function getStoreId(): ?int
    {
        $store = $this->storeManager->getDefaultStoreView();

        return $store !== null ? (int)$store->getId() : null;
    }
}
