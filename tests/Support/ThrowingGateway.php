<?php

namespace Tests\Support;

use App\Payments\PaymentGateway;
use App\Payments\PaymentResult;
use RuntimeException;

final class ThrowingGateway implements PaymentGateway
{
    public function charge(int $amountMinor, string $currency, array $options = []): PaymentResult
    {
        throw new RuntimeException('Sensitive provider details must not be returned.');
    }
}
