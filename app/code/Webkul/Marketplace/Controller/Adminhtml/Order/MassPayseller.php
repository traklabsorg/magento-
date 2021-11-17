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

namespace Webkul\Marketplace\Controller\Adminhtml\Order;

use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use Webkul\Marketplace\Model\ResourceModel\Saleslist\CollectionFactory;
use Webkul\Marketplace\Helper\Data as MpHelper;
use Webkul\Marketplace\Helper\Email as MpEmailHelper;
use Webkul\Marketplace\Model\SellertransactionFactory;
use Webkul\Marketplace\Model\SaleperpartnerFactory;
use Webkul\Marketplace\Model\OrdersFactory;
use Webkul\Marketplace\Helper\Notification as NotificationHelper;

/**
 * Class MassPayseller used to mass Payseller.
 */
class MassPayseller extends \Magento\Backend\App\Action
{
    /**
     * @var Filter
     */
    public $filter;

    /**
     * @var CollectionFactory
     */
    public $collectionFactory;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    public $date;

    /**
     * @var \Magento\Framework\Stdlib\DateTime
     */
    public $dateTime;

    /** @var \Magento\Sales\Model\OrderRepository */
    public $orderRepository;

    /**
     * @var MpHelper
     */
    protected $mpHelper;

    /**
     * @var MpEmailHelper
     */
    protected $mpEmailHelper;

    /**
     * @var SellertransactionFactory
     */
    protected $sellertransaction;

    /**
     * @var SaleperpartnerFactory
     */
    protected $saleperpartner;

    /**
     * @var OrdersFactory
     */
    protected $ordersModel;

    /**
     * @var NotificationHelper
     */
    protected $notificationHelper;

    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $customerModel;

    /**
     * @param Context                                     $context
     * @param Filter                                      $filter
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param \Magento\Framework\Stdlib\DateTime          $dateTime
     * @param \Magento\Sales\Model\OrderRepository        $orderRepository
     * @param CollectionFactory                           $collectionFactory
     * @param MpHelper                                    $mpHelper
     * @param MpEmailHelper                               $mpEmailHelper
     * @param SellertransactionFactory                    $sellertransaction
     * @param SaleperpartnerFactory                       $saleperpartner
     * @param OrdersFactory                               $ordersModel
     * @param NotificationHelper                          $notificationHelper
     * @param \Magento\Customer\Model\CustomerFactory     $customerModel
     */
    public function __construct(
        Context $context,
        Filter $filter,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\Framework\Stdlib\DateTime $dateTime,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        CollectionFactory $collectionFactory,
        MpHelper $mpHelper,
        MpEmailHelper $mpEmailHelper,
        SellertransactionFactory $sellertransaction,
        SaleperpartnerFactory $saleperpartner,
        OrdersFactory $ordersModel,
        NotificationHelper $notificationHelper,
        \Magento\Customer\Model\CustomerFactory $customerModel
    ) {
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        parent::__construct($context);
        $this->date = $date;
        $this->dateTime = $dateTime;
        $this->orderRepository = $orderRepository;
        $this->mpHelper = $mpHelper;
        $this->mpEmailHelper = $mpEmailHelper;
        $this->sellertransaction = $sellertransaction;
        $this->saleperpartner = $saleperpartner;
        $this->ordersModel = $ordersModel;
        $this->notificationHelper = $notificationHelper;
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
        try {
            $wholedata = $this->getRequest()->getParams();
            $actparterprocost = 0;
            $totalamount = 0;
            $sellerId = $wholedata['seller_id'];
            $wksellerorderids = explode(',', $wholedata['wksellerorderids']);

            $helper = $this->mpHelper;
            $taxToSeller = $helper->getConfigTaxManage();

            $orderinfo = '';

            $collection = $this->collectionFactory->create()
            ->addFieldToFilter('entity_id', ['in' => $wksellerorderids])
            ->addFieldToFilter('order_id', ['neq' => 0])
            ->addFieldToFilter('paid_status', 0)
            ->addFieldToFilter('cpprostatus', ['neq' => 0]);
            foreach ($collection as $row) {
                $sellerId = $row->getSellerId();
                $order = $this->orderRepository->get($row['order_id']);
                $taxAmount = $row['total_tax'];
                $marketplaceOrders = $this->ordersModel->create()
                ->getCollection()
                ->addFieldToFilter('order_id', $row['order_id'])
                ->addFieldToFilter('seller_id', $sellerId);
                foreach ($marketplaceOrders as $tracking) {
                    $taxToSeller = $tracking['tax_to_seller'];
                }
                $vendorTaxAmount = 0;
                if ($taxToSeller) {
                    $vendorTaxAmount = $taxAmount;
                }
                $codCharges = 0;
                $shippingCharges = 0;
                if (!empty($row['cod_charges'])) {
                    $codCharges = $row->getCodCharges();
                }
                if ($row->getIsShipping() == 1) {
                    foreach ($marketplaceOrders as $tracking) {
                        $shippingamount = $tracking->getShippingCharges();
                        $refundedShippingAmount = $tracking->getRefundedShippingCharges();
                        $shippingCharges = $shippingamount - $refundedShippingAmount;
                    }
                }
                $actparterprocost = $actparterprocost +
                    $row->getActualSellerAmount() +
                    $vendorTaxAmount +
                    $codCharges +
                    $shippingCharges -
                    $row->getAppliedCouponAmount();
                $totalamount = $totalamount +
                    $row->getTotalAmount() +
                    $taxAmount +
                    $codCharges +
                    $shippingCharges -
                    $row->getAppliedCouponAmount();
                $orderinfo = $orderinfo."<tr>
                    <td class='item-info'>".$row['magerealorder_id']."</td>
                    <td class='item-info'>".$row['magepro_name']."</td>
                    <td class='item-qty'>".$row['magequantity']."</td>
                    <td class='item-price'>".$order->formatBasePrice($row['magepro_price'])."</td>
                    <td class='item-price'>".$order->formatBasePrice($row['total_commission'])."</td>
                    <td class='item-price'>".$order->formatBasePrice($row['actual_seller_amount']).'</td>
                </tr>';
            }
            if ($actparterprocost) {
                $collectionverifyread = $this->saleperpartner->create()
                ->getCollection()
                ->addFieldToFilter('seller_id', $sellerId);
                if (count($collectionverifyread) >= 1) {
                    $id = 0;
                    $totalremain = 0;
                    $amountpaid = 0;
                    foreach ($collectionverifyread as $verifyrow) {
                        $id = $verifyrow->getId();
                        if ($verifyrow->getAmountRemain() >= $actparterprocost) {
                            $totalremain = $verifyrow->getAmountRemain() - $actparterprocost;
                        }
                        $amountpaid = $verifyrow->getAmountReceived();
                    }
                    $verifyrow = $this->saleperpartner->create()->load($id);
                    $totalrecived = $actparterprocost + $amountpaid;
                    $verifyrow->setLastAmountPaid($actparterprocost);
                    $verifyrow->setAmountReceived($totalrecived);
                    $verifyrow->setAmountRemain($totalremain);
                    $verifyrow->setUpdatedAt($this->date->gmtDate());
                    $verifyrow->save();
                } else {
                    $percent = $helper->getConfigCommissionRate();
                    $collectionf = $this->saleperpartner->create();
                    $collectionf->setSellerId($sellerId);
                    $collectionf->setTotalSale($totalamount);
                    $collectionf->setLastAmountPaid($actparterprocost);
                    $collectionf->setAmountReceived($actparterprocost);
                    $collectionf->setAmountRemain(0);
                    $collectionf->setCommissionRate($percent);
                    $collectionf->setTotalCommission($totalamount - $actparterprocost);
                    $collectionf->setCreatedAt($this->date->gmtDate());
                    $collectionf->setUpdatedAt($this->date->gmtDate());
                    $collectionf->save();
                }

                $uniqueId = $this->checktransid();
                $transid = '';
                $transactionNumber = '';
                if ($uniqueId != '') {
                    $sellerTrans = $this->sellertransaction->create()
                    ->getCollection()
                    ->addFieldToFilter('transaction_id', $uniqueId);
                    if (count($sellerTrans)) {
                        $id = 0;
                        foreach ($sellerTrans as $value) {
                            $id = $value->getId();
                        }
                        if ($id) {
                            $this->sellertransaction->create()->load($id)->delete();
                        }
                    }
                    $sellerTrans = $this->sellertransaction->create();
                    $sellerTrans->setTransactionId($uniqueId);
                    $sellerTrans->setTransactionAmount($actparterprocost);
                    $sellerTrans->setType('Manual');
                    $sellerTrans->setMethod('Manual');
                    $sellerTrans->setSellerId($sellerId);
                    $sellerTrans->setCustomNote($wholedata['seller_pay_reason']);
                    $sellerTrans->setCreatedAt($this->date->gmtDate());
                    $sellerTrans->setUpdatedAt($this->date->gmtDate());
                    $sellerTrans->setSellerPendingNotification(1);
                    $sellerTrans = $sellerTrans->save();
                    $transid = $sellerTrans->getId();
                    $transactionNumber = $sellerTrans->getTransactionId();
                    $this->notificationHelper->saveNotification(
                        \Webkul\Marketplace\Model\Notification::TYPE_TRANSACTION,
                        $transid,
                        $transid
                    );
                }

                foreach ($collection as $collectionData) {
                    $collection->setSalesListData(
                        $collectionData->getId(),
                        ['paid_status' => 1, 'trans_id' => $transid]
                    );
                    $data['trans_id'] = $transactionNumber;
                    $data['mp_trans_row_id'] = $transid;
                    $data['mp_saleslist_row_id'] = $collectionData->getId();
                    $data['id'] = $collectionData->getOrderId();
                    $data['seller_id'] = $collectionData->getSellerId();
                    $this->_eventManager->dispatch(
                        'mp_pay_seller',
                        [$data]
                    );
                }

                $seller = $this->customerModel->create()->load($sellerId);

                $emailTempVariables = [];

                $adminStoreEmail = $helper->getAdminEmailId();
                $adminEmail = $adminStoreEmail ? $adminStoreEmail : $helper->getDefaultTransEmailId();
                $adminUsername = $helper->getAdminName();

                $senderInfo = [];
                $receiverInfo = [];

                $receiverInfo = [
                    'name' => $seller->getName(),
                    'email' => $seller->getEmail()
                ];
                $senderInfo = [
                    'name' => $adminUsername,
                    'email' => $adminEmail
                ];

                $emailTempVariables['myvar1'] = $seller->getName();
                $emailTempVariables['myvar2'] = $transactionNumber;
                $emailTempVariables['myvar3'] = $this->date->gmtDate();
                $emailTempVariables['myvar4'] = $order->formatBasePrice($actparterprocost);
                $emailTempVariables['myvar5'] = $orderinfo;
                $emailTempVariables['myvar6'] = $wholedata['seller_pay_reason'];

                $this->mpEmailHelper->sendSellerPaymentEmail(
                    $emailTempVariables,
                    $senderInfo,
                    $receiverInfo
                );

                $this->messageManager->addSuccess(__('Payment has been successfully done for this seller'));
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addError($e->getMessage());
        } catch (\Exception $e) {
            $this->mpHelper->logDataInLogger(
                "controller_AdminHtml_Order_MassPayseller execute : ".$e->getMessage()
            );
            $this->messageManager->addError(__('We can\'t pay the seller right now. %1', $e->getMessage()));
        }
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $resultRedirect->setPath('marketplace/order/index', ['seller_id' => $sellerId]);
    }

    public function randString(
        $length,
        $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
    ) {
        $str = 'tr-';
        $count = strlen($charset);
        while ($length--) {
            $str .= $charset[random_int(0, $count - 1)];
        }

        return $str;
    }

    public function checktransid()
    {
        $uniqueId = $this->randString(11);
        $collection = $this->sellertransaction->create()
        ->getCollection()
        ->addFieldToFilter('transaction_id', $uniqueId);
        $i = 0;
        foreach ($collection as $value) {
            ++$i;
        }
        if ($i != 0) {
            $this->checktransid();
        } else {
            return $uniqueId;
        }
    }

    /**
     * Check for is allowed.
     *
     * @return bool
     */
    public function _isAllowed()
    {
        return $this->_authorization->isAllowed('Webkul_Marketplace::seller');
    }
}
