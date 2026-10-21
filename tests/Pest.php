<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

function apiToken(User $user): string
{
    return JWTAuth::fromUser($user);
}

function makeConfirmedOrder(User $user): Order
{
    $order = $user->orders()->create([
        'customer_name' => 'Test Customer',
        'customer_email' => 'customer@example.com',
        'status' => Order::STATUS_CONFIRMED,
        'total_minor' => 2468,
    ]);
    $order->items()->create([
        'product_name' => 'Test Product',
        'quantity' => 2,
        'unit_price_minor' => 1234,
        'line_total_minor' => 2468,
    ]);

    return $order;
}
