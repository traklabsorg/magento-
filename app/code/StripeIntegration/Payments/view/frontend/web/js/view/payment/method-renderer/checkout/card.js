/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Checkout/js/model/quote',
        'StripeIntegration_Payments/js/view/checkout/trialing_subscriptions',
        'StripeIntegration_Payments/js/view/payment/method-renderer/method',
        'stripejs',
        'domReady!'
    ],
    function (
        $,
        quote,
        trialingSubscriptions,
        Component
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                self: this,
                template: 'StripeIntegration_Payments/payment/redirect_form',
                code: "checkout_card",
                customRedirect: true
            },
            redirectAfterPlaceOrder: false,

            initObservable: function()
            {
                this._super();

                var params = window.checkoutConfig.payment["stripe_payments"].initParams;

                initStripe(params);

                var currentTotals = quote.totals();

                trialingSubscriptions().refresh(quote);

                quote.totals.subscribe(function (totals)
                {
                    if (JSON.stringify(totals.total_segments) == JSON.stringify(currentTotals.total_segments))
                        return;

                    currentTotals = totals;

                    trialingSubscriptions().refresh(quote);
                }
                , this);

                return this;
            },

            redirect: function(sessionId)
            {
                var self = this;

                try
                {
                    stripe.stripeJs.redirectToCheckout({ sessionId: sessionId }, self.onRedirectFailure);
                }
                catch (e)
                {
                    console.error(e);
                }
            },

            onRedirectFailure: function(result)
            {
                if (result.error)
                    alert(result.error.message);
                else
                    alert("An error has occurred.");
            },

            icons: function()
            {
                return window.checkoutConfig.payment["stripe_payments"].icons;
            }
        });
    }
);
