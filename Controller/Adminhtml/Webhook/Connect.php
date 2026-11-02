<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Controller\Adminhtml\Webhook;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Shubo\Qshpr\Model\Status\WebhookRegistrar;

/**
 * "Connect status updates": registers (or re-points) the store's status
 * webhook at Quickshipper, using the login of the website being edited.
 */
class Connect extends Action implements HttpPostActionInterface
{
    /** Same permission as editing the delivery methods settings. */
    public const ADMIN_RESOURCE = 'Magento_Shipping::carriers';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param WebhookRegistrar $registrar
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger Bound to ShuboQshprLogger in di.xml.
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly WebhookRegistrar $registrar,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    /**
     * Connect, and say what happened.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            $websiteId = (int)$this->getRequest()->getParam('website');
            $storeId = $websiteId > 0
                ? (int)$this->storeManager->getGroup(
                    (int)$this->storeManager->getWebsite($websiteId)->getDefaultGroupId()
                )->getDefaultStoreId()
                : (int)$this->storeManager->getDefaultStoreView()?->getId();
            $connected = $this->registrar->connect($storeId);

            return $result->setData([
                'success' => true,
                'message' => (string)__(
                    'Status updates connected: Quickshipper will send status changes to %1 (webhook %2, %3). '
                    . 'Quickshipper must be able to reach this address from the internet.',
                    $connected['url'],
                    $connected['webhookId'],
                    $connected['action'] === WebhookRegistrar::ACTION_CREATED ? __('new') : __('updated')
                ),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('webhook: connecting status updates failed: %s', $e->getMessage()),
                ['exception' => $e]
            );

            return $result->setData([
                'success' => false,
                'message' => (string)__('Could not connect status updates: %1', $e->getMessage()),
            ]);
        }
    }
}
