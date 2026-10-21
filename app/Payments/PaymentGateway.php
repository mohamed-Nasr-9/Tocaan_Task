<?php

namespace App\Payments;

interface PaymentGateway
{
    /** @param array<string, mixed> $options */
    public function charge(int $amountMinor, string $currency, array $options = []): PaymentResult;
}
