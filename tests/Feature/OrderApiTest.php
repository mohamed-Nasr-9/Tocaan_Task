<?php

use App\Models\Payment;
use App\Models\User;

it('creates orders with a server calculated exact total', function () {
    $user = User::factory()->create();

    $response = $this->withToken(apiToken($user))->postJson('/api/v1/orders', [
        'customer_name' => 'Alex Example',
        'customer_email' => 'alex@example.com',
        'items' => [
            ['product_name' => 'Coffee', 'quantity' => 2, 'price' => '12.34'],
            ['product_name' => 'Tea', 'quantity' => 1, 'price' => '5.34'],
        ],
        'total_minor' => 1,
        'status' => 'confirmed',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.total', '30.02')
        ->assertJsonPath('data.items.0.line_total', '24.68');
    $this->assertDatabaseHas('orders', ['user_id' => $user->id, 'total_minor' => 3002, 'status' => 'pending']);
});

it('rejects prices with more than two fractional digits', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user))->postJson('/api/v1/orders', [
        'customer_name' => 'Alex Example',
        'customer_email' => 'alex@example.com',
        'items' => [['product_name' => 'Coffee', 'quantity' => 1, 'price' => '1.005']],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.0.price');

    $this->assertDatabaseCount('orders', 0);
});

it('rejects malformed order items', function () {
    $user = User::factory()->create();

    $this->withToken(apiToken($user))->postJson('/api/v1/orders', [
        'customer_name' => 'Alex Example',
        'customer_email' => 'alex@example.com',
        'items' => [['product_name' => 'Coffee', 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.0.price');
});

it('returns only the owner orders and applies status and pagination filters', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $pending = $owner->orders()->create(['customer_name' => 'Pending', 'customer_email' => 'pending@example.com', 'status' => 'pending', 'total_minor' => 100]);
    $owner->orders()->create(['customer_name' => 'Confirmed', 'customer_email' => 'confirmed@example.com', 'status' => 'confirmed', 'total_minor' => 200]);
    $other->orders()->create(['customer_name' => 'Other', 'customer_email' => 'other@example.com', 'status' => 'pending', 'total_minor' => 300]);

    $response = $this->withToken(apiToken($owner))->getJson('/api/v1/orders?status=pending&per_page=1');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id)->assertJsonPath('meta.total', 1);
});

it('returns 404 when a user requests another users order', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $order = $owner->orders()->create(['customer_name' => 'Owner', 'customer_email' => 'owner@example.com', 'total_minor' => 100]);

    $this->withToken(apiToken($other))->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
});

it('allows pending orders to be confirmed and rejects invalid status transitions', function () {
    $user = User::factory()->create();
    $order = $user->orders()->create(['customer_name' => 'Alex', 'customer_email' => 'alex@example.com', 'total_minor' => 100]);
    $this->withToken(apiToken($user));

    $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'confirmed'])->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('deletes orders without payments', function () {
    $user = User::factory()->create();
    $order = $user->orders()->create(['customer_name' => 'Alex', 'customer_email' => 'alex@example.com', 'total_minor' => 100]);

    $this->withToken(apiToken($user))->deleteJson("/api/v1/orders/{$order->id}")->assertNoContent();
    $this->assertDatabaseMissing('orders', ['id' => $order->id]);
});

it('refuses to delete orders with any payment history', function (string $status) {
    $user = User::factory()->create();
    $order = $user->orders()->create(['customer_name' => 'Alex', 'customer_email' => 'alex@example.com', 'total_minor' => 100]);
    $user->payments()->create([
        'order_id' => $order->id,
        'status' => $status,
        'method' => 'credit_card',
        'amount_minor' => 100,
        'currency' => 'USD',
        'idempotency_key' => "delete-{$status}",
    ]);

    $this->withToken(apiToken($user))->deleteJson("/api/v1/orders/{$order->id}")->assertUnprocessable();
    $this->assertModelExists($order);
})->with([
    'pending' => Payment::STATUS_PENDING,
    'successful' => Payment::STATUS_SUCCESSFUL,
    'failed' => Payment::STATUS_FAILED,
]);

it('preserves failed payment amount snapshots when an order is edited', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);
    $payment = $user->payments()->create([
        'order_id' => $order->id,
        'status' => Payment::STATUS_FAILED,
        'method' => 'paypal',
        'amount_minor' => 2468,
        'currency' => 'USD',
        'idempotency_key' => 'failed-snapshot',
    ]);

    $this->withToken(apiToken($user))->patchJson("/api/v1/orders/{$order->id}", [
        'items' => [['product_name' => 'New Product', 'quantity' => 1, 'price' => '50.00']],
    ])->assertOk()->assertJsonPath('data.total', '50.00');

    expect($payment->refresh()->amount_minor)->toBe(2468);
    expect($order->refresh()->total_minor)->toBe(5000);
});

it('rejects order edits while a payment is pending or after a successful payment', function (string $paymentStatus) {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);
    $user->payments()->create([
        'order_id' => $order->id,
        'status' => $paymentStatus,
        'method' => 'paypal',
        'amount_minor' => 2468,
        'currency' => 'USD',
        'idempotency_key' => "edit-{$paymentStatus}",
    ]);

    $this->withToken(apiToken($user))->patchJson("/api/v1/orders/{$order->id}", ['customer_name' => 'Changed'])
        ->assertUnprocessable()->assertJsonValidationErrors('order');
    expect($order->refresh()->customer_name)->toBe('Test Customer');
})->with([
    'pending payment' => Payment::STATUS_PENDING,
    'successful payment' => Payment::STATUS_SUCCESSFUL,
]);
