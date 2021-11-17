<?php
/**
 * Webkul Software.
 *
 * @category  Webkul
 * @package   Webkul_Marketplace
 * @author    Webkul
 * @copyright Copyright (c) Webkul Software Private Limited (https://webkul.com)
 * @license   https://store.webkul.com/license.html
 */

namespace Webkul\Marketplace\Controller\Adminhtml\Seller;

use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use Webkul\Marketplace\Model\ResourceModel\Seller\CollectionFactory;
use Magento\Catalog\Model\Indexer\Product\Price\Processor;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Webkul\Marketplace\Helper\Data as MpHelper;
use Webkul\Marketplace\Helper\Email as MpEmailHelper;

/**
 * Class MassDisapprove used to multiple seller disapproved.
 */
class MassDisapprove extends \Magento\Backend\App\Action
{
    /**
     * @var Filter
     */
    protected $filter;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $_date;

    /**
     * @var \Magento\Framework\Stdlib\DateTime
     */
    protected $dateTime;
    /**
     * Store manager.
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;
    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $_productRepository;

    /**
     * @var \Magento\Catalog\Model\Indexer\Product\Price\Processor
     */
    protected $_productPriceIndexerProcessor;

    /**
     * @var ProductAction
     */
    protected $productAction;

    /**
     * @var MpHelper
     */
    protected $mpHelper;

    /**
     * @var MpEmailHelper
     */
    protected $mpEmailHelper;

    /**
     * @var \Webkul\Marketplace\Model\ProductFactory
     */
    protected $productModel;

    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $customerModel;

    /**
     * @param Context                                     $context
     * @param Filter                                      $filter
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param \Magento\Framework\Stdlib\DateTime          $dateTime
     * @param CollectionFactory                           $collectionFactory
     * @param Processor                                   $productPriceIndexerProcessor
     * @param ProductAction                               $productAction
     * @param MpHelper                                    $mpHelper
     * @param MpEmailHelper                               $mpEmailHelper
     * @param \Webkul\Marketplace\Model\ProductFactory    $productModel
     * @param \Magento\Customer\Model\CustomerFactory     $customerModel
     */
    public function __construct(
        Context $context,
        Filter $filter,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\Framework\Stdlib\DateTime $dateTime,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        CollectionFactory $collectionFactory,
        Processor $productPriceIndexerProcessor,
        ProductAction $productAction,
        MpHelper $mpHelper,
        MpEmailHelper $mpEmailHelper,
        \Webkul\Marketplace\Model\ProductFactory $productModel,
        \Magento\Customer\Model\CustomerFactory $customerModel
    ) {
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->_storeManager = $storeManager;
        $this->_productRepository = $productRepository;
        parent::__construct($context);
        $this->_date = $date;
        $this->dateTime = $dateTime;
        $this->_productPriceIndexerProcessor = $productPriceIndexerProcessor;
        $this->productAction = $productAction;
        $this->mpHelper = $mpHelper;
        $this->mpEmailHelper = $mpEmailHelper;
        $this->productModel = $productModel;
        $this->customerModel = $customerModel;
    }

    /**
     * Execute action.
     *
     * @return \Magento\Backend\Model\View\Result\Redirect
     *
     * @throws \Magento\Framework\Exception\LocalizedException|\Exception
     */
    public function execute()
    {
        $allStores = $this->_storeManager->getStores();
        $status = \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED;
        $sellerStatus = \Webkul\Marketplace\Model\Seller::STATUS_DISABLED;
        $customerModel = $this->customerModel->create();
        $helper = $this->mpHelper;
        $sellerIds = [];
        $collection = $this->filter->getCollection(
            $this->collectionFactory->create()
        );
        foreach ($collection as $item) {
            $sellerIds[] = $item->getSellerId();
            $item->setIsSeller($sellerStatus);
            $item->setUpdatedAt($this->_date->gmtDate());
            $item->save();
            $sellerProduct = $this->productModel->create()
            ->getCollection()
            ->addFieldToFilter('seller_id', $item->getSellerId());

            if ($sellerProduct->getSize()) {
                $productIds = $sellerProduct->getAllIds();
                $coditionArr = [];
                foreach ($productIds as $key => $id) {
                    $condition = "`mageproduct_id`=".$id;
                    array_push($coditionArr, $condition);
                }
                $coditionData = implode(' OR ', $coditionArr);

                $sellerProduct->setProductData(
                    $coditionData,
                    ['status' => $status]
                );
                foreach ($allStores as $store) {
                    $storeId = $store->getData('store_id');
                    $this->productAction->updateAttributes(
                        $productIds,
                        ['status' => $status],
                        $storeId
                    );
                }

                $this->productAction->updateAttributes($productIds, ['status' => $status], 0);

                $this->_productPriceIndexerProcessor->reindexList($productIds);
            }

            $adminStoremail = $helper->getAdminEmailId();
            $adminEmail = $adminStoremail ? $adminStoremail : $helper->getDefaultTransEmailId();
            $adminUsername = $helper->getAdminName();

            $seller = $customerModel->load($item->getSellerId());

            $emailTempVariables['myvar1'] = $seller->getName();
            $emailTempVariables['myvar2'] = $this->_storeManager->getStore()->getBaseUrl().'marketplace/account/login';
            $senderInfo = [
                'name' => $adminUsername,
                'email' => $adminEmail,
            ];
            $receiverInfo = [
                'name' => $seller->getName(),
                'email' => $seller->getEmail(),
            ];
            $this->mpEmailHelper->sendSellerDisapproveMail(
                $emailTempVariables,
                $senderInfo,
                $receiverInfo
            );
            $this->_eventManager->dispatch(
                'mp_disapprove_seller',
                ['seller' => $seller]
            );
        }
        $model = $this->collectionFactory->create()
        ->addFieldToFilter('seller_id', ['in' => $sellerIds])
        ->addFieldToFilter('is_seller', ['neq'=>$sellerStatus]);
        foreach ($model as $value) {
            $value->setIsSeller($sellerStatus);
            $value->setUpdatedAt($this->_date->gmtDate());
            $value->save();
        }

        $this->messageManager->addSuccess(
            __(
                'A total of %1 record(s) have been disapproved.',
                $collection->getSize()
            )
        );

        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(
            ResultFactory::TYPE_REDIRECT
        );

        return $resultRedirect->setPath('*/*/');
    }

    /**
     * Check for is allowed.
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Webkul_Marketplace::seller');
    }
}
