<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'customer_email' => ['sometimes', 'required', 'email', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'required', 'string', 'in:pending,confirmed,cancelled'],
            'items' => ['sometimes', 'required', 'array', 'min:1', 'max:50'],
            'items.*.product_name' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:1000'],
            'items.*.price' => ['required_with:items', 'regex:/^(?:0|[1-9]\d{0,5})(?:\.\d{1,2})?$/', 'gt:0'],
        ];
    }
}
