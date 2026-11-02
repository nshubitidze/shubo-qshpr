<?php

declare(strict_types=1);

namespace Shubo\Qshpr\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Connect status updates" button in the Quickshipper settings. A config
 * field needs a Block to render anything other than an input; the work is
 * done by the Adminhtml Webhook\Connect controller.
 */
class ConnectStatusUpdates extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Shubo_Qshpr::system/config/connect-status-updates.phtml';

    /**
     * A button has no scope checkbox or "use default" choice.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    /**
     * The button's HTML.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setData('html_id', $element->getHtmlId());

        return $this->_toHtml();
    }

    /**
     * Where the button posts, carrying the website being edited.
     *
     * @return string
     */
    public function getConnectUrl(): string
    {
        $website = (int)$this->getRequest()->getParam('website');

        return $this->getUrl('shuboqshpr/webhook/connect', $website > 0 ? ['website' => $website] : []);
    }
}
