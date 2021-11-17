<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Source;

class CheckoutMode
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 0,
                'label' => __('Stripe Elements (recommended for most websites)')
            ],
            [
                'value' => 1,
                'label' => __('Stripe Checkout (recommended for PWA storefronts and native mobile apps)')
            ],
        ];
    }
}
