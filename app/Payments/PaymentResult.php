<?php

namespace App\Payments;

final readonly class PaymentResult
{
    public function __construct(
        public bool $successful,
        public string $code,
        public string $message,
        public ?string $reference = null,
    ) {}
}
