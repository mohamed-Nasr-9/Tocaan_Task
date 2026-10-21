<?php

namespace App\Payments;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class PaymentGatewayException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('The payment gateway could not process the request.');
    }
}
