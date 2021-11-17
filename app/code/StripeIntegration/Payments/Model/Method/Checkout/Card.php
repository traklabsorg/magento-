<?php

namespace StripeIntegration\Payments\Model\Method\Checkout;

use Magento\Framework\Exception\LocalizedException;

class Card extends \StripeIntegration\Payments\Model\Method\Checkout\Checkout
{
    const METHOD_CODE = 'stripe_payments_checkout_card';

    protected $_code = self::METHOD_CODE;

    protected $type = 'checkout_card';

    public function getTitle()
    {
        return $this->config->getConfigData("title");
    }

    public function isEnabled($quote)
    {
        return ($this->config->isEnabled() &&
            $this->config->getConfigData("checkout_mode") == 1) &&
            !$this->helper->isAdmin() &&
            !$this->helper->isMultiShipping();
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($this->helper->isRecurringOrder($this))
            return true;

        if (!$this->isEnabled($quote))
            return false;

        return parent::isAvailable($quote);
    }

    public function cancel(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        $method = $payment->getMethod();

        // Captured
        $creditmemo = $payment->getCreditmemo();
        if (!empty($creditmemo))
        {
            $rate = $creditmemo->getBaseToOrderRate();
            if (!empty($rate) && is_numeric($rate) && $rate > 0)
            {
                $amount = round($amount * $rate, 2);
                $diff = $amount - $payment->getAmountPaid();
                if ($diff > 0 && $diff <= 1) // Solves a currency conversion rounding issue (Magento rounds .5 down)
                    $amount = $payment->getAmountPaid();
            }
        }

        // Authorized
        $amount = (empty($amount)) ? $payment->getOrder()->getTotalDue() : $amount;
        $currency = $payment->getOrder()->getOrderCurrencyCode();

        try
        {
            $this->helper->refundPaymentIntent($payment, $amount, $currency);
        }
        catch (\Exception $e)
        {
            $this->helper->dieWithError($e->getMessage());
        }

        return $this;
    }
}
