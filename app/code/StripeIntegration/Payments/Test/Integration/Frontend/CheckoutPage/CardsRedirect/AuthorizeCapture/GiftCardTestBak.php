<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\CardsRedirect\AuthorizeCapture;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\SessionFactory as CheckoutSessionFactory;
use PHPUnit\Framework\Constraint\StringContains;

class GiftCardTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();

        $this->checkoutSession = $this->objectManager->get(CheckoutSessionFactory::class)->create();
        $this->transportBuilder = $this->objectManager->get(\Magento\TestFramework\Mail\Template\TransportBuilderMock::class);
        $this->eventManager = $this->objectManager->get(\Magento\Framework\Event\ManagerInterface::class);
        $this->orderSender = $this->objectManager->get(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class);
        $this->cartManagement = $this->objectManager->get(\Magento\Quote\Api\CartManagementInterface::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/active 1
     * @magentoConfigFixture current_store payment/stripe_payments_basic/stripe_mode test
     * @magentoConfigFixture current_store payment/stripe_payments/checkout_mode 1
     *
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeys.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/Products.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Quotes/USGuestQuote.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Carts/NormalCart.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Quotes/ShippingAddress/NewYorkAddress.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Quotes/ShippingMethod/FlatRateShippingMethod.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Quotes/BillingAddress/NewYorkAddress.php
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Quotes/PaymentMethod/StripeCheckoutCard.php
     * @magentoDataFixture Magento/GiftCardAccount/_files/giftcardaccount.php
     */
    public function testDiscountApplied()
    {
        if (false /* Check if this is Adobe Commerce before running the tests */)
        {
            $quote = $this->objectManager->create(\Magento\Quote\Model\Quote::class);
            $quote->load('test_quote', 'reserved_order_id');

            $giftCardAccount = $this->objectManager->create(\Magento\GiftCardAccount\Model\Giftcardaccount::class);
            $giftCardAccount->loadByCode('giftcardaccount_fixture');
            $giftCardAccount->addToCart(true, $quote);

            $order = $this->cartManagement->submit($quote);

            $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
            $this->assertNotEmpty($checkoutSessionId);

            $session = $this->stripeConfig->getStripeClient()->checkout->sessions->retrieve($checkoutSessionId);

            $this->assertEquals($order->getGrandTotal(), $session->amount_total / 100);

            return $order;
        }
    }
}
