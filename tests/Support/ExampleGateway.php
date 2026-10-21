<?php

namespace Tests\Support;

use App\Payments\PaymentGateway;
use App\Payments\PaymentResult;

final class ExampleGateway implements PaymentGateway
{
    public static int $chargeCount = 0;

    public function charge(int $amountMinor, string $currency, array $options = []): PaymentResult
    {
        self::$chargeCount++;

        return new PaymentResult(true, 'example_approved', 'Approved by the test gateway.', 'example-reference');
    }
}
