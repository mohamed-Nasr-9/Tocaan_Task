<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $payments = $request->user()->payments()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return PaymentResource::collection($payments)->response();
    }

    public function show(Request $request, int $payment): PaymentResource
    {
        return new PaymentResource($request->user()->payments()->findOrFail($payment));
    }

    public function forOrder(Request $request, int $order): JsonResponse
    {
        $ownedOrder = $request->user()->orders()->findOrFail($order);
        $filters = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $payments = $ownedOrder->payments()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return PaymentResource::collection($payments)->response();
    }

    public function showForOrder(Request $request, int $order, int $payment): PaymentResource
    {
        $ownedOrder = $request->user()->orders()->findOrFail($order);
        $ownedPayment = $ownedOrder->payments()->whereBelongsTo($request->user())->findOrFail($payment);

        return new PaymentResource($ownedPayment);
    }

    public function store(StorePaymentRequest $request, int $order, PaymentService $payments): JsonResponse
    {
        $ownedOrder = $request->user()->orders()->findOrFail($order);
        $payment = $payments->process(
            $request->user(),
            $ownedOrder,
            $request->idempotencyKey(),
            $request->safe()->only(['method', 'simulate_outcome']),
        );

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }
}
