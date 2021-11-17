<?php
namespace Webepower\SellerRegistration\Controller\Login;

use Magento\Framework\App\Action\Action;

class Index extends Action
{
    /**
     * @param \Webkul\Marketplace\Helper\Data
     */
    private $helper;

    /**
     * @param Magento\Framework\View\Result\PageFactory
     */
    private $resultPageFactory;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Webkul\Marketplace\Helper\Data $helper,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
    ) {
        $this->helper = $helper;
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        if ($this->helper->isCustomerLoggedIn()) {
            return $this->resultRedirectFactory
                        ->create()
                        ->setPath('*/*/account');
        } else {
            $resultPage = $this->resultPageFactory->create();
            $resultPage->getConfig()->getTitle()->set(__('Seller LogIn'));
            return $resultPage;
        }
    }
}
