<?php

namespace App\Payments;

use Illuminate\Support\Str;

abstract class SimulatedGateway implements PaymentGateway
{
    /** @param array<string, mixed> $options */
    public function charge(int $amountMinor, string $currency, array $options = []): PaymentResult
    {
        if (($options['simulate_outcome'] ?? 'success') === 'failure') {
            return new PaymentResult(false, 'simulated_decline', 'The simulated gateway declined this attempt.');
        }

        return new PaymentResult(true, 'simulated_approved', 'The simulated payment was approved.', 'sim_'.Str::lower(Str::random(16)));
    }
}
