<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Validator\Exception;

class Rollback
{
    protected $data;
    protected $helper;

    public function __construct(
        \Magento\Framework\Session\Generic $session,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \StripeIntegration\Payments\Helper\GenericFactory $helperFactory
    ) {
        $this->session = $session;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->helperFactory = $helperFactory;

        $this->data = $this->session->getRollbackData();

        if (empty($this->data))
            $this->reset();
    }

    public function reset()
    {
        $this->data = [
            'subscriptions' => [],
            'authorizations' => [],
            'charges' => [],
            'cards' => [],
            'sources' => [],
            'invoices' => [],
            'invoiceItems' => [],
        ];
        $this->session->setRollbackData($this->data);
    }

    public function addSubscription($id)
    {
        $this->data['subscriptions'][$id] = $id;
        $this->session->setRollbackData($this->data);
    }

    public function addCharge($id)
    {
        $this->data['charges'][$id] = $id;
        $this->session->setRollbackData($this->data);
    }

    public function addAuthorization($id)
    {
        $this->data['authorizations'][$id] = $id;
        $this->session->setRollbackData($this->data);
    }

    public function addCard($customerId, $cardId)
    {
        $this->data['cards'][$cardId] = $customerId;
        $this->session->setRollbackData($this->data);
    }

    public function addSource($customerId, $sourceId)
    {
        $this->data['sources'][$sourceId] = $customerId;
        $this->session->setRollbackData($this->data);
    }

    public function addStripeObject($object)
    {
        $type = $object->getType();
        if (!isset($this->data[$type]))
            return;

        $this->data[$type][$object->getId()] = $object;
    }

    public function logException($e)
    {
        $log = false;

        // Only log it if the rollback will run
        foreach ($this->data as $key => $value)
        {
            if (!empty($value))
            {
                $log = true;
                break;
            }
        }

        if ($log)
        {
            \StripeIntegration\Payments\Helper\Logger::log("ROLLBACK: An error has occurred while placing an order and a rollback will be initiated.");
            \StripeIntegration\Payments\Helper\Logger::log("ROLLBACK: ERROR: " . $e->getMessage());
            \StripeIntegration\Payments\Helper\Logger::log("ROLLBACK: STACK TRACE:");
            \StripeIntegration\Payments\Helper\Logger::log($e->getTraceAsString());

            $msg = __("A refund has been automatically issued back to the customer because of an error at the checkout page: %1. More details logged under var/log/system.log.", $e->getMessage());

            if (!isset($this->helper))
                $this->helper = $this->helperFactory->create();

            $this->helper->sendPaymentFailedEmail($msg);
        }
    }

    public function run($e = null)
    {
        if ($e)
            $this->logException($e);

        foreach ($this->data['authorizations'] as $id)
        {
            try
            {
                \StripeIntegration\Payments\Model\Config::$stripeClient->paymentIntents->cancel($id, []);
            }
            catch (\Exception $e)
            {
                \StripeIntegration\Payments\Helper\Logger::log("Error while canceling authorization $id: " . $e->getMessage());
            }
        }

        foreach ($this->data['charges'] as $id)
        {
            try
            {
                \StripeIntegration\Payments\Model\Config::$stripeClient->refunds->create(['charge' => $id]);
            }
            catch (\Exception $e)
            {
                \StripeIntegration\Payments\Helper\Logger::log("Error while refunding charge $id: " . $e->getMessage());
            }
        }

        foreach ($this->data['subscriptions'] as $id)
        {
            try
            {
                $this->subscriptionFactory->create()->cancel($id);
            }
            catch (\Exception $e)
            {
                \StripeIntegration\Payments\Helper\Logger::log("Error while canceling subscription $id: " . $e->getMessage());
            }
        }

        foreach ($this->data['cards'] as $id => $customer)
        {
            try
            {
                \StripeIntegration\Payments\Model\Config::$stripeClient->customers->deleteSource($customer, $id, []);
            }
            catch (\Exception $e)
            {
                \StripeIntegration\Payments\Helper\Logger::log("Error while deleting saved card $id: " . $e->getMessage());
            }
        }

        foreach ($this->data['sources'] as $id => $customer)
        {
            try
            {
                \Stripe\Customer::deleteSource($customer, $id);
            }
            catch (\Exception $e)
            {
                \StripeIntegration\Payments\Helper\Logger::log("Error while deleting source $id: " . $e->getMessage());
            }
        }

        foreach ($this->data['invoices'] as $id => $stripeObject)
        {
            try
            {
                $stripeObject->destroy();
            }
            catch (\Exception $e)
            {
                \StripeIntegration\Payments\Helper\Logger::log("Error while deleting invoice $id: " . $e->getMessage());
            }
        }

        foreach ($this->data['invoiceItems'] as $id => $stripeObject)
        {
            try
            {
                $stripeObject->destroy();
            }
            catch (\Exception $e)
            {
                \StripeIntegration\Payments\Helper\Logger::log("Error while deleting invoice item $id: " . $e->getMessage());
            }
        }

        $this->reset();
    }
}
