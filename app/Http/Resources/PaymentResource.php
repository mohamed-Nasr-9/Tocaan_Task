<?php

namespace App\Http\Resources;

use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
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
            'order_id' => $this->order_id,
            'status' => $this->status,
            'method' => $this->method,
            'amount' => Money::format($this->amount_minor),
            'currency' => $this->currency,
            'gateway_reference' => $this->gateway_reference,
            'result' => ['code' => $this->result_code, 'message' => $this->result_message],
            'created_at' => $this->created_at,
        ];
    }
}
