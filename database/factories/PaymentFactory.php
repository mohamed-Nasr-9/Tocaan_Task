<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'order_id' => Order::factory(),
            'status' => Payment::STATUS_FAILED,
            'method' => 'credit_card',
            'amount_minor' => 1000,
            'currency' => 'USD',
            'gateway_reference' => null,
            'result_code' => 'simulated_decline',
            'result_message' => 'The simulated gateway declined this attempt.',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (): array => [
            'status' => Payment::STATUS_SUCCESSFUL,
            'result_code' => 'simulated_approved',
            'result_message' => 'The simulated payment was approved.',
            'gateway_reference' => 'sim_'.Str::random(16),
        ]);
    }
}
