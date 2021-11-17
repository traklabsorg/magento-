<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;

class Webhooks
{
    public function __construct(
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\App\Response\Http $response,
        \StripeIntegration\Payments\Logger\WebhooksLogger $webhooksLogger,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Helper\Api $api,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\InvoiceFactory $invoiceFactory,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Sales\Model\Order\Invoice $invoiceModel,
        \Magento\Framework\UrlInterface $urlInterface,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection $webhookCollection,
        \StripeIntegration\Payments\Helper\WebhooksSetup $webhooksSetup
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->webhooksLogger = $webhooksLogger;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->api = $api;
        $this->helper = $helper;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->eventManager = $eventManager;
        $this->invoiceFactory = $invoiceFactory;
        $this->orderSender = $orderSender;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceModel = $invoiceModel;
        $this->urlInterface = $urlInterface;
        $this->webhookCollection = $webhookCollection;
        $this->webhooksSetup = $webhooksSetup;
    }

    public function dispatchEvent()
    {
        try
        {
            if ($this->request->getMethod() == 'GET')
                throw new WebhookException("Webhooks are working correctly!", 200);

            // Retrieve the request's body and parse it as JSON
            $body = $this->request->getContent();

            $event = json_decode($body, true);
            $stdEvent = json_decode($body);

            if (empty($event['type']))
                throw new WebhookException(__("Unknown event type"));

            if ($event['type'] == "product.created")
            {
                $this->onProductCreated($event, $stdEvent);
                $this->log("200 OK");
                return;
            }

            $eventType = "stripe_payments_webhook_" . str_replace(".", "_", $event['type']);

            if (isset($event['data']['object']['type'])) // Bancontact, Giropay, iDEAL
                $eventType .= "_" . $event['data']['object']['type'];
            else if (isset($event['data']['object']['source']['type'])) // SOFORT and SEPA
                $eventType .= "_" . $event['data']['object']['source']['type'];
            else if (isset($event['data']['object']['source']['object'])) // ACH bank accounts
                $eventType .= "_" . $event['data']['object']['source']['object'];
            else if (isset($event['data']['object']['payment_method_types']))
                $eventType .= "_" . implode("_", $event['data']['object']['payment_method_types']);
            else if (isset($event['data']['object']['payment_method_details']))
                $eventType .= "_" . $event['data']['object']['payment_method_details']['type'];

            // Magento 2 event names do not allow numbers
            $eventType = str_replace("p24", "przelewy", $eventType);

            $this->log("Received $eventType");

            if ($this->cache->load($event['id']) && empty($this->request->getParam('dev')))
                throw new WebhookException(__("Event with ID %1 has already been processed.", $event['id']), 202);

            $this->eventManager->dispatch($eventType, array(
                    'arrEvent' => $event,
                    'stdEvent' => $stdEvent,
                    'object' => $event['data']['object']
                ));

            $this->cache($event);
            $this->log("200 OK");
        }
        catch (WebhookException $e)
        {
            $this->error($e->getMessage(), $e->statusCode, true);

            if (!empty($e->statusCode) && !empty($event) && ($e->statusCode < 400 || $e->statusCode > 499))
                $this->cache($event);
        }
        catch (\Exception $e)
        {
            $this->log($e->getMessage());
            $this->log($e->getTraceAsString());
            $this->error($e->getMessage());

            if (!empty($e->statusCode) && !empty($event) && ($e->statusCode < 400 || $e->statusCode > 499))
                $this->cache($event);
        }
    }

    public function onProductCreated($event, $stdEvent)
    {
        if ($event['data']['object']['name'] == "Webhook Configuration")
        {
            $storeCode = $event['data']['object']['metadata']['store_code'];
            $mode = ucfirst($event['data']['object']['metadata']['mode']) . " Mode";
            $this->log("Received automatic webhook configuration for $storeCode ($mode)");
            $this->eventManager->dispatch("automatic_webhook_configuration", array('event' => $stdEvent));
        }
        else if ($event['data']['object']['name'] == "Webhook Ping")
        {
            $this->webhookCollection->pong($event['data']['object']['metadata']['pk']);
            $this->webhooksSetup->deleteProduct($event['data']['object']['id']);
        }
    }

    public function error($msg, $status = null, $displayError = false)
    {
        if ($status && $status > 0)
            $responseStatus = $status;
        else
            $responseStatus = 202;

        $this->log("$responseStatus $msg");

        if (!$displayError)
            $msg = "An error has occurred. Please check var/log/stripe_payments_webhooks.log for more details.";

        $this->response
            ->setStatusCode($responseStatus)
            ->setHeader('Content-Type', 'text/plain', $overwriteExisting = true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setContent($msg);
    }

    public function log($msg)
    {
        // Magento 2.0.0 - 2.4.3
        if (method_exists($this->webhooksLogger, 'addInfo'))
            $this->webhooksLogger->addInfo($msg);
        // Magento 2.4.4+
        else
            $this->webhooksLogger->info($msg);
    }

    public function verifyWebhookSignature($storeId)
    {
        $signingSecret = $this->config->getWebhooksSigningSecret();
        if (empty($signingSecret))
            return;

        try
        {
            if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE']))
                throw new WebhookException("Webhook signature could not be found in the request payload", 400);

            $payload = $this->request->getContent();
            $event = \Stripe\Webhook::constructEvent($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'], $signingSecret);
        }
        catch(\UnexpectedValueException $e)
        {
            throw new WebhookException("Invalid webhook payload", 400);
        }
        catch(\Stripe\Exception\SignatureVerificationException $e)
        {
            throw new WebhookException("Invalid webhook signature", 400);
        }
    }

    public function cache($event)
    {
        if (empty($event['id']))
            throw new WebhookException("No event ID specified");

        $this->cache->save("processed", $event['id'], array('stripe_payments_webhooks_events_processed'), 24 * 60 * 60);
    }

    public function getOrderID($event)
    {
        if (empty($event['type']))
            throw new \Exception("Invalid event data");

        switch ($event['type'])
        {
            case 'invoice.payment_succeeded':
            case 'invoice.payment_failed':

                foreach ($event['data']['object']['lines']['data'] as $data)
                {
                    if ($data['type'] == "subscription")
                        return $data['metadata']['Order #'];
                }

                return null;

            default:
                return null;
        }
    }

    public function getOrderIdFromObject($object)
    {
        // For most payment methods, the order ID is here
        if (!empty($object['metadata']['Order #']))
            return $object['metadata']['Order #'];

        // For invoices created from the Magento admin, we have the order ID in the stripe_invoices DB table
        if ($object['object'] == 'invoice')
        {
            $entry = $this->invoiceFactory->create()->load($object['id'], 'invoice_id');
            if ($entry->getOrderIncrementId())
                return $entry->getOrderIncrementId();

            foreach ($object['lines']['data'] as $lineItem)
            {
                // The invoice may include a subscription bought using stripe_payments_checkout_card
                if ($lineItem['type'] == "subscription" && !empty($lineItem['metadata']['Order #']))
                    return $lineItem['metadata']['Order #'];
            }
        }

        // If the merchant refunds a charge of a recurring subscription order from the Stripe dashboard, we need to drill down to the parent subscription
        if ($object['object'] == 'charge' && !empty($object['invoice']) && !empty($object['customer']))
        {
            $this->config->reInitStripeFromCustomerId($object['customer']);
            $stripe = $this->config->getStripeClient();
            $invoice = $stripe->invoices->retrieve($object['invoice'], ['expand' => ['subscription']]);
            if (!empty($invoice->subscription->metadata->{"Order #"}))
                return $invoice->subscription->metadata->{"Order #"};
        }

        throw new WebhookException("Could not find the Order # associated with this webhook event", 202);
    }

    public function loadOrderFromInvoiceId($invoiceId, $event)
    {
        $entry = $this->invoiceFactory->create()->load($invoiceId, 'invoice_id');
        if (!$entry->getOrderIncrementId())
            throw new WebhookException("We could not find the order for the invoice associated with this charge.", 202);

        $order = $this->loadOrderByIncrementId($entry->getOrderIncrementId(), $event);

        $this->initStripeFrom($order, $event);

        return $order;
    }

    public function loadOrderFromEvent($orderId, $event)
    {
        if (empty($orderId))
            throw new WebhookException("Received {$event['type']} webhook but there was no Order # in the source's metadata", 202);

        $order = $this->loadOrderByIncrementId($orderId, $event);

        $this->initStripeFrom($order, $event);

        return $order;
    }

    public function loadSubscriptionProductFromEvent($event)
    {
        if (empty($event['data']['object']['metadata']['Product ID']))
            throw new WebhookException("Could not find a Product ID in the event's metadata.");

        return $this->helper->loadProductById($event['data']['object']['metadata']['Product ID']);
    }

    public function initStripeFrom($order, $event)
    {
        $paymentMethodCode = $order->getPayment()->getMethod();
        if (strpos($paymentMethodCode, "stripe") !== 0)
            throw new WebhookException("Order #$orderId was not placed using Stripe", 202);

        // For multi-stripe account configurations, load the correct Stripe API key from the correct store view
        if (isset($event['data']['object']['livemode']))
            $mode = ($event['data']['object']['livemode'] ? "live" : "test");
        else
            $mode = null;
        $this->config->reInitStripe($order->getStoreId(), $order->getOrderCurrencyCode(), $mode);
        $this->webhookCollection->pong($this->config->getPublishableKey($mode));
        $this->verifyWebhookSignature($order->getStoreId());
    }

    public function getKlarnaOrderNumber($event)
    {
        $sourceId = $event['data']['object']['id'];

        $wait = 60;
        do
        {
            // With Klarna the source is authorized in the front-end before we have an order number, so we load it from the cache instead
            // because the order number does not exist in the source's metadata
            $orderId = $this->cache->load($sourceId);

            // There is a possibility we receive the webhook before the order is committed to the database (because Magento is slow), so we wait if not found
            if (empty($orderId))
            {
                $wait--;
                sleep(1);
            }
        }
        while (empty($orderId) && $wait > 0);

        if (empty($orderId))
            throw new WebhookException(__("Received %1 webhook but there was no Order # in the source's metadata", $event['type']), 202);

        return $orderId;
    }

    public function loadOrderByIncrementId($orderId, $event, $count = 5)
    {
        $order = $this->helper->loadOrderByIncrementId($orderId);

        if (empty($order) || empty($order->getId()) && $count >= 0)
        {
            // Webhooks Race Condition: Sometimes we may receive the webhook before Magento commits the order to the database,
            // so we give it a few seconds and try again. Can happen when multiple subscriptions are purchased together.
            sleep(4);
            return $this->loadOrderByIncrementId($orderId, $event, $count - 1);
        }

        if (empty($order) || empty($order->getId()))
            throw new WebhookException(__("Received %1 webhook with Order #%2 but could not find the order in Magento.", $event['type'], $orderId), 202);

        return $order;
    }

    // Called after a source.chargable event
    public function charge($order, $object, $addTransaction = true, $sendNewOrderEmail = true)
    {
        $orderId = $order->getIncrementId();

        $payment = $order->getPayment();
        if (!$payment)
            throw new WebhookException("Could not load payment method for order #$orderId");

        $orderSourceId = $payment->getAdditionalInformation('source_id');
        $webhookSourceId = $object['id'];
        if ($orderSourceId != $webhookSourceId)
            throw new WebhookException("Received source.chargeable webhook for order #$orderId but the source ID on the webhook $webhookSourceId was different than the one on the order $orderSourceId");

        $stripeParams = $this->config->getStripeParamsFrom($order);

        // Reusable sources may not have an amount set
        if (empty($object['amount']))
        {
            $amount = $stripeParams['amount'];
        }
        else
        {
            $amount = $object['amount'];
        }

        $params = array(
            "amount" => $amount,
            "currency" => $object['currency'],
            "source" => $webhookSourceId,
            "description" => $stripeParams['description'],
            "metadata" => $stripeParams['metadata']
        );

        $capture = $this->getCaptureParamFor($object);
        if ($capture !== null)
            $params["capture"] = $capture;

        $statementDescription = $this->getStatementDescriptionFor($order, $object);
        if ($statementDescription !== null)
            $params["statement_descriptor"] = $statementDescription;

        // For reusable sources, we will always need a customer ID
        $customerStripeId = $payment->getAdditionalInformation('customer_stripe_id');
        if (!empty($customerStripeId))
            $params["customer"] = $customerStripeId;

        try
        {
            $charge = \Stripe\Charge::create($params);

            $payment->setTransactionId($charge->id);
            $payment->setLastTransId($charge->id);
            $payment->setIsTransactionClosed(0);

            // Log additional info about the payment
            $info = $this->helper->getClearSourceInfo($object[$object['type']]);
            $payment->setAdditionalInformation('source_info', json_encode($info));
            $payment->save();

            if ($addTransaction)
            {
                if (!$charge->captured)
                    $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
                else
                    $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
                //Transaction::TYPE_PAYMENT

                $transaction = $payment->addTransaction($transactionType, null, false);
                $transaction->save();
            }

            if ($charge->status == 'succeeded')
            {
                if ($charge->captured == false)
                    // $invoice = $this->helper->invoicePendingOrder($order, \Magento\Sales\Model\Order\Invoice::NOT_CAPTURE, $charge->id);
                    return;
                else
                    $invoice = $this->helper->invoiceOrder($order, $charge->id);

                if ($sendNewOrderEmail)
                    $this->helper->sendNewOrderEmailFor($order);
            }
            // SEPA, SOFORT and other asynchronous methods will be pending
            else if ($charge->status == 'pending')
            {
                $invoice = $this->helper->invoicePendingOrder($order, $charge->id);

                if ($sendNewOrderEmail)
                    $this->helper->sendNewOrderEmailFor($order);
            }
            else
            {
                // In theory we should never have failed charges because they would throw an exception
                $comment = "Authorization failed. Transaction ID: {$charge->id}. Charge status: {$charge->status}";
                $order->addStatusHistoryComment($comment);
                $order->save();
            }

            return $charge;
        }
        catch (\Stripe\Exception\CardException $e)
        {
            $comment = "Order could not be charged because of a card error: " . $e->getMessage();
            $order->addStatusHistoryComment($comment);
            $order->save();
            $this->log($e->getMessage());
            throw new WebhookException($e->getMessage(), 202);
        }
        catch (\Exception $e)
        {
            $comment = "Order could not be charged because of server side error: " . $e->getMessage();
            $order->addStatusHistoryComment($comment);
            $order->save();
            $this->log($e->getMessage());
            throw new WebhookException($e->getMessage(), 202);
        }
    }

    public function getCaptureParamFor($object)
    {
        if ($object["type"] == "klarna")
        {
            $action = $this->config->getConfigData('payment_action', 'klarna');
            return ($action == "authorize_capture");
        }

        return null;
    }

    public function getStatementDescriptionFor($order, $object)
    {
        if ($object["type"] == "klarna")
        {
            return "Order #" . $order->getIncrementId();
        }

        return null;
    }

    public function getCurrentRefundFrom($webhookData)
    {
        $lastRefundDate = 0;
        $currentRefund = null;

        foreach ($webhookData['refunds']['data'] as $refund)
        {
            // There might be multiple refunds, and we are looking for the most recent one
            if ($refund['created'] > $lastRefundDate)
            {
                $lastRefundDate = $refund['created'];
                $currentRefund = $refund;
            }
        }

        return $currentRefund;
    }

    public function refundOfflineOrCancel($order)
    {
        $dbTransaction = $this->transactionFactory->create();

        if ($order->canCreditmemo())
        {
            // In theory we should only have a single Invoice for the stripe_payments_invoice payment method
            foreach($order->getInvoiceCollection() as $invoice)
            {
                $creditmemo = $this->creditmemoFactory->createByOrder($order);
                $creditmemo->setInvoice($invoice);
                $this->creditmemoService->refund($creditmemo, true);
                $dbTransaction->addObject($creditmemo);
                $dbTransaction->addObject($invoice);
            }

        }
        else
        {
            $order->cancel();
        }

        $dbTransaction->addObject($order)->save();
    }

    public function refund($order, $object)
    {
        if ($order->getState() == "holded" && $order->canUnhold())
            $order->unhold();

        $dbTransaction = $this->transactionFactory->create();

        // Check if the order has an invoice with the charge ID we are refunding
        $chargeId = $object['id'];
        $chargeAmount = $object['amount'];
        $currentRefund = $this->getCurrentRefundFrom($object);
        $refundAmount = $currentRefund['amount'];
        $currency = $object['currency'];
        $invoice = null;
        $baseToOrderRate = $order->getBaseToOrderRate();
        $payment = $order->getPayment();
        if (isset($object["payment_intent"]))
            $pi = $object["payment_intent"];
        else
            $pi = "not_exists";

        // Calculate the real refund amount
        if (!$this->helper->isZeroDecimal($currency))
        {
            $refundAmount /= 100;
        }

        $baseTotalNotRefunded = $order->getBaseGrandTotal() - $order->getBaseTotalRefunded();
        $baseOrderCurrency = strtolower($order->getBaseCurrencyCode());
        $orderCurrency = strtolower($order->getCurrencyCode());

        if ($baseOrderCurrency != $currency)
            $refundAmount = round($refundAmount / $order->getBaseToOrderRate(), 4);

        $isPartialRefund = ($baseTotalNotRefunded > $refundAmount);

        if (!$order->canCreditmemo())
        {
            if ($order->canCancel())
            {
                if (!$isPartialRefund)
                {
                    $order->cancel();
                    $order->save();
                }
                else if ($isPartialRefund)
                {
                    // Don't do anything on a partial refund, we expect a paynemt_intent.succeeded to arrive for the partial capture.
                }
                return;
            }

            throw new WebhookException("Order #{$order->getIncrementId()} cannot be (or has already been) refunded.");
        }

        foreach($order->getInvoiceCollection() as $item)
        {
            $transactionId = $this->helper->cleanToken($item->getTransactionId());
            if ($transactionId == $chargeId || $transactionId == $pi)
                $invoice = $item;
        }

        if (empty($invoice))
            throw new WebhookException("Could not find an invoice with transaction ID $chargeId.");

        if (!$invoice->canRefund())
            throw new WebhookException("Invoice #{$invoice->getIncrementId()} cannot be (or has already been) refunded.");

        if ($baseTotalNotRefunded < $refundAmount)
            throw new WebhookException("Error: Trying to refund an amount that is larger than the invoice amount");

        $creditmemo = $this->creditmemoFactory->createByOrder($order);
        $creditmemo->setInvoice($invoice);

        if ($baseTotalNotRefunded > $refundAmount)
        {
            $baseDiff = $baseTotalNotRefunded - $refundAmount;
            $creditmemo->setAdjustmentPositive($baseDiff);
            $creditmemo->setItems([]);
        }

        $creditmemo->setBaseGrandTotal($refundAmount);
        $creditmemo->setGrandTotal($refundAmount * $baseToOrderRate);

        $this->creditmemoService->refund($creditmemo, true);

        $comment = __("We refunded %1 through Stripe.", $this->helper->addCurrencySymbol($refundAmount, $currency));
        $order->addStatusToHistory($status = false, $comment);

        $dbTransaction->addObject($invoice)
            ->addObject($order)
            ->addObject($creditmemo)
            ->addObject($payment)
            ->save();
    }

    public function removeEndpoint()
    {
        $url = $this->urlInterface->getCurrentUrl();
        $endpoints = \Stripe\WebhookEndpoint::all();
        foreach ($endpoints as $endpoint)
        {
            if (strpos($url, $endpoint->url) === 0)
            {
                $endpoint = \Stripe\WebhookEndpoint::retrieve($endpoint->id);
                $endpoint->delete();
            }
        }
    }

    // When multiple events arrive at the same time, lock the current process so that we don't get DB deadlocks
    // Works similar to a queuing system, but is real time rather than cron-based
    public function lock()
    {
        $wait = 70; // seconds to wait for lock
        $sleep = 2; // poll every X seconds
        do
        {
            $lock = $this->cache->load("stripe_payments_webhooks_lock");
            if ($lock)
            {
                sleep($sleep);
                $wait -= $sleep;
            }

        } while ($lock && $wait > 0);

        $this->cache->save(1, "stripe_payments_webhooks_lock", array(), $lifetime = 60);
    }

    public function unlock()
    {
        $this->cache->remove("stripe_payments_webhooks_lock");
    }
}
