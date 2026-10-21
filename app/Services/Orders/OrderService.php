<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private Money $money) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data): Order {
            $items = $this->normalizeItems($data['items']);
            $total = array_sum(array_column($items, 'line_total_minor'));

            $order = $user->orders()->create([
                ...$this->customerData($data),
                'status' => Order::STATUS_PENDING,
                'total_minor' => $total,
            ]);

            $order->items()->createMany($items);

            return $order->load('items');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $payments = $order->payments()->lockForUpdate()->get();

            if ($payments->contains(fn (Payment $payment): bool => $payment->status === Payment::STATUS_PENDING || $payment->status === Payment::STATUS_SUCCESSFUL)) {
                throw ValidationException::withMessages(['order' => ['Orders cannot be changed while a payment is pending or after a successful payment.']]);
            }

            if (isset($data['status'])) {
                $allowedTransitions = [
                    Order::STATUS_PENDING => [Order::STATUS_CONFIRMED, Order::STATUS_CANCELLED],
                    Order::STATUS_CONFIRMED => [Order::STATUS_CANCELLED],
                    Order::STATUS_CANCELLED => [],
                ];

                if (! in_array($data['status'], $allowedTransitions[$order->status] ?? [], true)) {
                    throw ValidationException::withMessages(['status' => ['The requested order status transition is not allowed.']]);
                }
            }

            $order->fill($this->customerData($data));

            if (isset($data['status'])) {
                $order->status = $data['status'];
            }

            if (isset($data['items'])) {
                $items = $this->normalizeItems($data['items']);
                $order->items()->delete();
                $order->items()->createMany($items);
                $order->total_minor = array_sum(array_column($items, 'line_total_minor'));
            }

            $order->save();

            return $order->load('items');
        });
    }

    public function delete(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->payments()->exists()) {
                throw ValidationException::withMessages(['order' => ['Orders with payment attempts cannot be deleted.']]);
            }

            $order->delete();
        });
    }

    /** @param list<array{product_name: string, quantity: int, price: string|int|float}> $items
     * @return list<array{product_name: string, quantity: int, unit_price_minor: int, line_total_minor: int}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $unitPrice = $this->money->toMinorUnits($item['price']);
            $lineTotal = $unitPrice * (int) $item['quantity'];

            if ($lineTotal > PHP_INT_MAX - array_sum(array_column($normalized, 'line_total_minor'))) {
                throw ValidationException::withMessages(['items' => ['The order total exceeds the supported range.']]);
            }

            $normalized[] = [
                'product_name' => $item['product_name'],
                'quantity' => (int) $item['quantity'],
                'unit_price_minor' => $unitPrice,
                'line_total_minor' => $lineTotal,
            ];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function customerData(array $data): array
    {
        return array_intersect_key($data, array_flip(['customer_name', 'customer_email', 'customer_phone']));
    }
}
