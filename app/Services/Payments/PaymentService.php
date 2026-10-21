<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\GatewayResolver;
use App\Payments\PaymentGatewayException;
use App\Payments\PaymentResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private GatewayResolver $gateways) {}

    /** @param array{method: string, simulate_outcome: string} $data */
    public function process(User $user, Order $order, string $idempotencyKey, array $data): Payment
    {
        try {
            return DB::transaction(function () use ($user, $order, $idempotencyKey, $data): Payment {
                $order = Order::query()->whereBelongsTo($user)->lockForUpdate()->findOrFail($order->id);
                $existing = Payment::query()->whereBelongsTo($user)->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    $this->assertSameRequest($existing, $order, $data);

                    return $existing;
                }

                if ($order->status !== Order::STATUS_CONFIRMED) {
                    throw ValidationException::withMessages(['order' => ['Only confirmed orders can be paid.']]);
                }

                if ($order->payments()->where('status', Payment::STATUS_PENDING)->exists()) {
                    throw ValidationException::withMessages(['order' => ['A payment attempt is already pending for this order.']]);
                }

                if ($order->payments()->where('status', Payment::STATUS_SUCCESSFUL)->exists()) {
                    throw ValidationException::withMessages(['order' => ['This order has already been paid successfully.']]);
                }

                $gateway = $this->gateways->resolve($data['method']);
                $payment = $user->payments()->create([
                    'order_id' => $order->id,
                    'status' => Payment::STATUS_PENDING,
                    'method' => $data['method'],
                    'simulate_outcome' => $data['simulate_outcome'],
                    'amount_minor' => $order->total_minor,
                    'currency' => config('payments.currency'),
                    'idempotency_key' => $idempotencyKey,
                ]);

                try {
                    $result = $gateway->charge($payment->amount_minor, $payment->currency, [
                        'simulate_outcome' => $data['simulate_outcome'],
                    ]);
                } catch (\Throwable) {
                    Log::warning('A payment gateway raised an unexpected error.', [
                        'order_id' => $order->id,
                        'method' => $data['method'],
                    ]);

                    throw new PaymentGatewayException;
                }

                return $this->finish($payment, $result);
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = Payment::query()->whereBelongsTo($user)->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                $this->assertSameRequest($existing, $order, $data);

                return $existing;
            }

            throw $exception;
        }
    }

    private function finish(Payment $payment, PaymentResult $result): Payment
    {
        $payment->update([
            'status' => $result->successful ? Payment::STATUS_SUCCESSFUL : Payment::STATUS_FAILED,
            'gateway_reference' => $result->reference,
            'result_code' => $result->code,
            'result_message' => $result->message,
        ]);

        return $payment->refresh();
    }

    /** @param array{method: string, simulate_outcome: string} $data */
    private function assertSameRequest(Payment $payment, Order $order, array $data): void
    {
        if ($payment->simulate_outcome === null) {
            throw ValidationException::withMessages(['idempotency_key' => ['This historical payment cannot be safely replayed. Use a new idempotency key.']]);
        }

        if ($payment->order_id !== $order->id || $payment->method !== $data['method'] || $payment->simulate_outcome !== $data['simulate_outcome']) {
            throw ValidationException::withMessages(['idempotency_key' => ['This key has already been used with a different payment request.']]);
        }
    }
}
