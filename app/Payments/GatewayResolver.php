<?php

namespace App\Payments;

use Illuminate\Validation\ValidationException;

class GatewayResolver
{
    /** @param array<string, class-string<PaymentGateway>> $gateways */
    public function __construct(private array $gateways) {}

    public function resolve(string $method): PaymentGateway
    {
        $class = $this->gateways[$method] ?? null;

        if ($class === null) {
            throw ValidationException::withMessages(['method' => ['The selected payment method is not supported.']]);
        }

        if (! in_array($method, config('payments.enabled_gateways', []), true)) {
            throw ValidationException::withMessages(['method' => ['The selected payment method is currently disabled.']]);
        }

        return app($class);
    }
}
