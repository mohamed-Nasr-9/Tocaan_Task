<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,confirmed,cancelled'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = $request->user()->orders()
            ->with('items')
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return OrderResource::collection($orders)->response();
    }

    public function store(StoreOrderRequest $request, OrderService $orders): JsonResponse
    {
        Gate::authorize('create', Order::class);
        $order = $orders->create($request->user(), $request->validated());

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $order): OrderResource
    {
        $ownedOrder = $request->user()->orders()->with('items')->findOrFail($order);
        Gate::authorize('view', $ownedOrder);

        return new OrderResource($ownedOrder);
    }

    public function update(UpdateOrderRequest $request, int $order, OrderService $orders): OrderResource
    {
        $ownedOrder = $request->user()->orders()->findOrFail($order);
        Gate::authorize('update', $ownedOrder);
        $updatedOrder = $orders->update($ownedOrder, $request->validated());

        return new OrderResource($updatedOrder);
    }

    public function destroy(Request $request, int $order, OrderService $orders): JsonResponse
    {
        $ownedOrder = $request->user()->orders()->findOrFail($order);
        Gate::authorize('delete', $ownedOrder);
        $orders->delete($ownedOrder);

        return response()->json(status: 204);
    }
}
