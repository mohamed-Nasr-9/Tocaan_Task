<?php

use App\Payments\CreditCardGateway;
use App\Payments\PaypalGateway;

return [
    'currency' => env('PAYMENTS_CURRENCY', 'USD'),
    'enabled_gateways' => array_filter(array_map('trim', explode(',', env('PAYMENTS_GATEWAYS', 'credit_card,paypal')))),
    'gateways' => [
        'credit_card' => CreditCardGateway::class,
        'paypal' => PaypalGateway::class,
    ],
];
