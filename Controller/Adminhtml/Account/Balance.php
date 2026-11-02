<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Controller\Adminhtml\Account;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Shubo\Qshpr\ViewModel\AdminBalance;

/**
 * The Quickshipper balance for the admin header, fetched after the page has
 * loaded, so a slow Quickshipper never slows the admin down.
 */
class Balance extends Action implements HttpGetActionInterface
{
    /** Who books couriers sees what booking costs. */
    public const ADMIN_RESOURCE = AdminBalance::ACL_RESOURCE;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param AdminBalance $adminBalance
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AdminBalance $adminBalance,
    ) {
        parent::__construct($context);
    }

    /**
     * The balance text, or null when it is hidden or cannot be read.
     *
     * @return Json
     */
    public function execute(): Json
    {
        // The admin is authorised by now and this call writes nothing to the
        // session: release its lock, or every other admin page this browser
        // opens waits while Quickshipper is slow to answer.
        $this->_session->writeClose();

        return $this->jsonFactory->create()->setData([
            'balance' => $this->adminBalance->isVisible() ? $this->adminBalance->getBalance() : null,
        ]);
    }
}
