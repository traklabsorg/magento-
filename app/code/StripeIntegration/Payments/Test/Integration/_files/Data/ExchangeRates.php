<?php
use Magento\Directory\Model\Currency;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

$rates = [
    'USD' => [
        'EUR' => '0.85',
        'MXN' => '20'
    ]
];

$currencyModel = $objectManager->create(Currency::class);
$currencyModel->saveRates($rates);
