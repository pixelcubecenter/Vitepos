<?php



$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Wallee\\Sdk\\' => array($vendorDir . '/wallee/sdk/lib'),
    'Stripe\\' => array($vendorDir . '/stripe/stripe-php/lib'),
    'Pusher\\' => array($vendorDir . '/pusher/pusher-php-server/src'),
    'Psr\\Log\\' => array($vendorDir . '/psr/log/Psr/Log'),
    'Psr\\Http\\Message\\' => array($vendorDir . '/psr/http-factory/src', $vendorDir . '/psr/http-message/src'),
    'Psr\\Http\\Client\\' => array($vendorDir . '/psr/http-client/src'),
    'GuzzleHttp\\Psr7\\' => array($vendorDir . '/guzzlehttp/psr7/src'),
    'GuzzleHttp\\Promise\\' => array($vendorDir . '/guzzlehttp/promises/src'),
    'GuzzleHttp\\' => array($vendorDir . '/guzzlehttp/guzzle/src'),
);
