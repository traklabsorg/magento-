<?php
namespace Webepower\SellerRegistration\Plugin\Customer;

use Magento\Framework\App\Action\Context;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Webkul\Marketplace\Helper\Data;

class LoginPost
{
    /**
     * @var Session
     */
    protected $session;

    /** @var Validator */
    protected $formKeyValidator;

    /** @var CustomerRepositoryInterface */
    protected $customerRepositoryInterface;

    /** @var ManagerInterface **/
    protected $messageManager;

    /** @var Http **/
    protected $responseHttp;

    protected $currentCustomer;

    /** @var AccountManagementInterface */
    protected $customerAccountManagement;

    /**
     * @param \Magento\Framework\App\Action\Context
     */
    private $context;

    /**
     * @param \Webkul\Marketplace\Helper\Data
     */
    private $mpHelper;

    /**
     * @param \Magento\Framework\App\Request\Http
     */
    private $request;

    /**
     * @param \Magento\Framework\App\Response\RedirectInterface
     */
    private $redirect;

    public function __construct(
        Context $context,
        Session $customerSession,
        Validator $formKeyValidator,
        CustomerRepositoryInterface $customerRepositoryInterface,
        ManagerInterface $messageManager,
        ResponseHttp $responseHttp,
        AccountManagementInterface $customerAccountManagement,
        Data $mpHelper,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\App\Response\RedirectInterface $redirect
    ) {
        $this->session = $customerSession;
        $this->formKeyValidator = $formKeyValidator;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->messageManager = $messageManager;
        $this->responseHttp = $responseHttp;
        $this->customerAccountManagement = $customerAccountManagement;
        $this->mpHelper = $mpHelper;
        $this->request = $request;
        $this->redirect = $redirect;
        $this->resultRedirectFactory = $context->getResultRedirectFactory();
    }

    public function aroundExecute(\Magento\Customer\Controller\Account\LoginPost $loginPost, \Closure $proceed)
    {
        if ($loginPost->getRequest()->isPost()) {
            $login = $loginPost->getRequest()->getPost('login');
            if (!empty($login['username']) && !empty($login['password'])) {
                try {
                    $customer = $this->getCustomer($login['username']);
                    //die('helloo'.$this->request->getModuleName()."<=>".$this->request->getControllerName()."<=>".$this->request->getActionName()."<=>".$this->request->getRouteName());
                    $referelUrl = $this->redirect->getRedirectUrl();
                    $arrayReferel = explode("/", $referelUrl);
                    if (in_array("sellerregistration", $arrayReferel)) {
                        if ($this->isAVendorAndAccountNotApproved($customer)) {
                            $proceed();
                            $resultRedirect = $this->resultRedirectFactory->create();
                            $resultRedirect->setPath('customer/account');
                            return $resultRedirect;
                        } else {
                            $this->messageManager->addErrorMessage(__('You are not a seller, Please login as normal user.'));
                            $resultRedirect = $this->resultRedirectFactory->create();
                            $resultRedirect->setPath('customer/account/login');
                            return $resultRedirect;
                        }
                    } else {
                        if ($this->isAVendorAndAccountNotApproved($customer)) {
                            $this->messageManager->addErrorMessage(__('You are a Seller, So Please login here.'));
                            $resultRedirect = $this->resultRedirectFactory->create();
                            $resultRedirect->setPath('sellerregistration/login/index');
                            return $resultRedirect;
                        } else {
                            $proceed();
                            $resultRedirect = $this->resultRedirectFactory->create();
                            $resultRedirect->setPath('customer/account/login');
                            return $resultRedirect;
                        }
                    }

                } catch (\Exception $e) {
                    $message = "Invalid User credentials.";
                    $this->messageManager->addErrorMessage($message);
                    $resultRedirect = $this->resultRedirectFactory->create();
                    $resultRedirect->setPath('customer/account/login');
                    return $resultRedirect;
                }
            } else {
                // call the original execute function
                return $proceed();
            }
        } else {
            // call the original execute function
            return $proceed();
        }
    }

    /**
     * @param $email
     * @return \Magento\Customer\Api\Data\CustomerInterface
     */
    public function getCustomer($email)
    {
        $this->currentCustomer = $this->customerRepositoryInterface->get($email);
        return $this->currentCustomer;
    }
    /**
     * Check if customer is a vendor and account is approved
     * @return bool
     */
    public function isAVendorAndAccountNotApproved($customer)
    {
        $sellerStatus = 0;
        $sellerId = $customer->getId();
        $model = $this->mpHelper->getSellerCollectionObj($sellerId);
        foreach ($model as $value) {
            if ($value->getIsSeller() == 1) {
                $sellerStatus = $value->getIsSeller();
            }
        }

        if ($sellerStatus == 1) {
            return true;
        }
        return false;
    }
}
