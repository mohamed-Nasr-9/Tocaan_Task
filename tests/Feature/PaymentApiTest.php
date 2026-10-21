<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\GatewayResolver;
use Tests\Support\ExampleGateway;
use Tests\Support\ThrowingGateway;

it('records successful and failed attempts through both configured gateways', function (string $method, string $outcome, string $status) {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);

    $response = $this->withToken(apiToken($user))->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => $method,
        'simulate_outcome' => $outcome,
    ], ['Idempotency-Key' => "{$method}-{$outcome}"]);

    $response->assertCreated()->assertJsonPath('data.status', $status)->assertJsonPath('data.amount', '24.68')->assertJsonPath('data.currency', 'USD');
    $this->assertDatabaseHas('payments', [
        'order_id' => $order->id,
        'status' => $status,
        'method' => $method,
        'amount_minor' => 2468,
    ]);
})->with([
    'credit card approves' => ['credit_card', 'success', Payment::STATUS_SUCCESSFUL],
    'credit card declines' => ['credit_card', 'failure', Payment::STATUS_FAILED],
    'PayPal approves' => ['paypal', 'success', Payment::STATUS_SUCCESSFUL],
    'PayPal declines' => ['paypal', 'failure', Payment::STATUS_FAILED],
]);

it('requires a confirmed order before creating a payment attempt', function (string $status) {
    $user = User::factory()->create();
    $order = $user->orders()->create([
        'customer_name' => 'Test Customer',
        'customer_email' => 'customer@example.com',
        'status' => $status,
        'total_minor' => 1000,
    ]);

    $this->withToken(apiToken($user))->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => 'credit_card',
        'simulate_outcome' => 'success',
    ], ['Idempotency-Key' => "not-confirmed-{$status}"])->assertUnprocessable()->assertJsonValidationErrors('order');
    $this->assertDatabaseCount('payments', 0);
})->with([
    'pending' => Order::STATUS_PENDING,
    'cancelled' => Order::STATUS_CANCELLED,
]);

it('allows a failed attempt to be retried but prevents a second successful payment', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);

    $this->withToken(apiToken($user))->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => 'credit_card',
        'simulate_outcome' => 'failure',
    ], ['Idempotency-Key' => 'retry-1'])->assertCreated()->assertJsonPath('data.status', Payment::STATUS_FAILED);

    $this->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => 'paypal',
        'simulate_outcome' => 'success',
    ], ['Idempotency-Key' => 'retry-2'])->assertCreated()->assertJsonPath('data.status', Payment::STATUS_SUCCESSFUL);

    $this->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => 'credit_card',
        'simulate_outcome' => 'success',
    ], ['Idempotency-Key' => 'retry-3'])->assertUnprocessable()->assertJsonValidationErrors('order');

    $this->assertDatabaseCount('payments', 2);
});

it('returns the original attempt for an identical idempotency key and rejects a changed payload', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);
    $this->withToken(apiToken($user));
    $payload = ['method' => 'credit_card', 'simulate_outcome' => 'failure'];

    $first = $this->postJson("/api/v1/orders/{$order->id}/payments", $payload, ['Idempotency-Key' => 'same-key']);
    $second = $this->postJson("/api/v1/orders/{$order->id}/payments", $payload, ['Idempotency-Key' => 'same-key']);

    $first->assertCreated();
    $second->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
    $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'credit_card', 'simulate_outcome' => 'success'], ['Idempotency-Key' => 'same-key'])
        ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $this->assertDatabaseCount('payments', 1);
});

it('rejects unsupported and disabled payment gateways without recording attempts', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);
    $this->withToken(apiToken($user));

    $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'bitcoin', 'simulate_outcome' => 'success'], ['Idempotency-Key' => 'unsupported'])
        ->assertUnprocessable()->assertJsonValidationErrors('method');

    config(['payments.enabled_gateways' => ['paypal']]);
    $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'credit_card', 'simulate_outcome' => 'success'], ['Idempotency-Key' => 'disabled'])
        ->assertUnprocessable()->assertJsonValidationErrors('method');
    $this->assertDatabaseCount('payments', 0);
});

it('uses a newly configured gateway without changing the payment service', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);
    config([
        'payments.gateways.example' => ExampleGateway::class,
        'payments.enabled_gateways' => ['example'],
    ]);
    app()->forgetInstance(GatewayResolver::class);

    $this->withToken(apiToken($user))->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => 'example',
        'simulate_outcome' => 'success',
    ], ['Idempotency-Key' => 'example-gateway'])
        ->assertCreated()->assertJsonPath('data.result.code', 'example_approved');
});

it('replays an identical request through a custom gateway without charging twice', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);
    config([
        'payments.gateways.example' => ExampleGateway::class,
        'payments.enabled_gateways' => ['example'],
    ]);
    app()->forgetInstance(GatewayResolver::class);
    ExampleGateway::$chargeCount = 0;
    $this->withToken(apiToken($user));
    $payload = ['method' => 'example', 'simulate_outcome' => 'success'];

    $first = $this->postJson("/api/v1/orders/{$order->id}/payments", $payload, ['Idempotency-Key' => 'example-replay']);
    $second = $this->postJson("/api/v1/orders/{$order->id}/payments", $payload, ['Idempotency-Key' => 'example-replay']);

    $first->assertCreated()->assertJsonPath('data.result.code', 'example_approved');
    $second->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
    $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'example', 'simulate_outcome' => 'failure'], ['Idempotency-Key' => 'example-replay'])
        ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $this->assertSame(1, ExampleGateway::$chargeCount);
    $this->assertDatabaseCount('payments', 1);
});

it('rejects replay of a historical payment without a persisted simulation outcome', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);
    $payment = $user->payments()->create([
        'order_id' => $order->id,
        'status' => Payment::STATUS_FAILED,
        'method' => 'credit_card',
        'amount_minor' => 2468,
        'currency' => 'USD',
        'result_code' => 'simulated_decline',
        'idempotency_key' => 'historical-payment',
    ]);

    $this->withToken(apiToken($user))->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => 'credit_card',
        'simulate_outcome' => 'failure',
    ], ['Idempotency-Key' => 'historical-payment'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key')
        ->assertJsonPath('errors.idempotency_key.0', 'This historical payment cannot be safely replayed. Use a new idempotency key.');

    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'simulate_outcome' => null]);
    $this->assertDatabaseCount('payments', 1);
});

it('hides unexpected gateway errors and rolls back an incomplete attempt', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);
    config([
        'payments.gateways.throwing' => ThrowingGateway::class,
        'payments.enabled_gateways' => ['throwing'],
    ]);
    app()->forgetInstance(GatewayResolver::class);

    $response = $this->withToken(apiToken($user))->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => 'throwing',
        'simulate_outcome' => 'success',
    ], ['Idempotency-Key' => 'unexpected-error']);

    $response->assertInternalServerError()->assertJsonPath('message', 'An unexpected error occurred.')->assertDontSee('Sensitive provider details');
    $this->assertDatabaseCount('payments', 0);
});

it('scopes payment history to the authenticated owner and order', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $order = makeConfirmedOrder($owner);
    $payment = $owner->payments()->create([
        'order_id' => $order->id,
        'status' => Payment::STATUS_FAILED,
        'method' => 'paypal',
        'amount_minor' => 2468,
        'currency' => 'USD',
        'idempotency_key' => 'owner-payment',
    ]);

    $this->withToken(apiToken($owner))->getJson('/api/v1/payments?per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/orders/{$order->id}/payments")->assertOk()->assertJsonPath('data.0.id', $payment->id);
    $this->getJson("/api/v1/orders/{$order->id}/payments/{$payment->id}")->assertOk()->assertJsonPath('data.id', $payment->id);

});

it('returns 404 when another user requests payment history or a payment', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $order = makeConfirmedOrder($owner);
    $payment = $owner->payments()->create([
        'order_id' => $order->id,
        'status' => Payment::STATUS_FAILED,
        'method' => 'paypal',
        'amount_minor' => 2468,
        'currency' => 'USD',
        'idempotency_key' => 'private-payment',
    ]);

    $this->withToken(apiToken($other))->getJson("/api/v1/orders/{$order->id}/payments")->assertNotFound();
    $this->getJson("/api/v1/payments/{$payment->id}")->assertNotFound();
    $this->getJson("/api/v1/orders/{$order->id}/payments/{$payment->id}")->assertNotFound();
});

it('requires an idempotency key and validates simulation outcome', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);

    $this->withToken(apiToken($user))->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => 'paypal',
        'simulate_outcome' => 'random',
    ])->assertUnprocessable()->assertJsonValidationErrors(['simulate_outcome', 'idempotency_key']);
});

it('does not start another attempt while an order has a pending payment', function () {
    $user = User::factory()->create();
    $order = makeConfirmedOrder($user);
    $user->payments()->create([
        'order_id' => $order->id,
        'status' => Payment::STATUS_PENDING,
        'method' => 'credit_card',
        'amount_minor' => 2468,
        'currency' => 'USD',
        'idempotency_key' => 'already-pending',
    ]);

    $this->withToken(apiToken($user))->postJson("/api/v1/orders/{$order->id}/payments", [
        'method' => 'paypal',
        'simulate_outcome' => 'success',
    ], ['Idempotency-Key' => 'second-pending'])
        ->assertUnprocessable()->assertJsonValidationErrors('order');
    $this->assertDatabaseCount('payments', 1);
});
