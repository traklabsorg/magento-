<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\CardsRedirect\AuthorizeCapture;

class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\Tests::class);

        $this->cartManagement = $this->objectManager->get(\Magento\Quote\Api\CartManagementInterface::class);
        $this->webhooks = $this->objectManager->get(\StripeIntegration\Payments\Helper\Webhooks::class);
        $this->request = $this->objectManager->get(\Magento\Framework\App\Request\Http::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
    }

    /**
     * @ticket MAGENTO-63
     *
     * @magentoConfigFixture current_store payment/stripe_payments/active 1
     * @magentoConfigFixture current_store payment/stripe_payments_basic/stripe_mode test
     * @magentoConfigFixture current_store payment/stripe_payments/checkout_mode 1
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeys.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Taxes.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Addresses.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Products.php
     */
    public function testMixedCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Mixed")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckoutCard");

        $order = $this->quote->placeOrder();

        $orderIncrementId = $order->getIncrementId();
        $currency = $order->getOrderCurrencyCode();
        $amount = $this->helper->convertMagentoAmountToStripeAmount($order->getGrandTotal(), $currency);

        // Retrieve the created session
        $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
        $this->assertNotEmpty($checkoutSessionId);

        $stripe = $this->stripeConfig->getStripeClient();
        $session = $stripe->checkout->sessions->retrieve($checkoutSessionId);
        $this->assertEquals($amount, $session->amount_total);

        // Confirm the payment
        $paymentMethod = $stripe->paymentMethods->create([
          'type' => 'card',
          'card' => [
            'number' => '4242424242424242',
            'exp_month' => 7,
            'exp_year' => 2022,
            'cvc' => '314',
          ],
          'billing_details' => [
            'address' => [
                'city' => 'New York',
                'country' => 'US',
                'line1' => '1255 Duncan Avenue',
                'postal_code' => "10013",
                'state' => "New York"
            ],
            'email' => 'jerryflint@example.com',
            'name' => 'Jerry Flint',
            'phone' => "917-535-4022"
          ],
        ]);

        $params = [
            'eid' => 'NA',
            'payment_method' => $paymentMethod->id,
            'expected_amount' => $session->amount_total,
            'expected_payment_method_type' => 'card'
        ];

        $response = $stripe->request('post', "/v1/payment_pages/{$session->id}/confirm", $params, $opts = null);

        // Assert order status, amount due, invoices
        $this->assertEquals("new", $order->getState());
        $this->assertEquals("pending", $order->getStatus());
        $this->assertEquals($session->amount_total / 100, round($order->getGrandTotal(), 2));
        $this->assertEquals($session->amount_total / 100, round($order->getTotalDue(), 2));
        $this->assertEquals(0, $order->getInvoiceCollection()->count());

        // Process the charge.succeeded event
        $paymentIntentId = $response->payment_intent->id;
        $paymentIntent = $this->stripeConfig->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
        $charge = $paymentIntent->charges->data[0];
        $this->tests->event()->trigger("charge.succeeded", $charge, $this);

        // Process invoice.payment_succeeded event
        $ordersCount = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();
        $customer = $stripe->customers->retrieve($session->customer);
        $invoiceId = $customer->subscriptions->data[0]->latest_invoice;
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoiceId, $this);

        // Ensure that no new order was created
        $newOrdersCount = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Refresh the order object
        $order = $this->helper->loadOrderByIncrementId($orderIncrementId);

        // Assert order status, amount due, invoices, invoice items, invoice totals
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals($session->amount_total / 100, round($order->getGrandTotal(), 2));
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($session->amount_total / 100, round($order->getTotalPaid(), 2));
        $this->assertEquals(1, $order->getInvoiceCollection()->count());

        // Process a recurring subscription billing webhook
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoiceId, $this);

        // Get the newly created order
        $newOrder = $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->setOrder('entity_id','DESC')->getFirstItem();

        // Assert new order, invoices, invoice items, invoice totals
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());
    }
}
