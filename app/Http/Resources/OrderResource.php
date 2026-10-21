<?php

namespace App\Http\Resources;

use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => ['name' => $this->customer_name, 'email' => $this->customer_email, 'phone' => $this->customer_phone],
            'status' => $this->status,
            'total' => Money::format($this->total_minor),
            'currency' => config('payments.currency'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => Money::format($item->unit_price_minor),
                'line_total' => Money::format($item->line_total_minor),
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
