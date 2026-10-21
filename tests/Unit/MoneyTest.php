<?php

use App\Services\Money;

it('converts decimal amounts to exact integer minor units', function () {
    $money = new Money;

    expect($money->toMinorUnits('12.34'))->toBe(1234);
    expect(Money::format(1234))->toBe('12.34');
    expect($money->toMinorUnits('7'))->toBe(700);
});

it('rejects zero, excess precision, and values above the supported price limit', function (string $amount) {
    expect(fn () => (new Money)->toMinorUnits($amount))->toThrow(InvalidArgumentException::class);
})->with([
    'zero' => '0.00',
    'negative' => '-1.00',
    'excess precision' => '1.005',
    'too large' => '1000000.00',
    'scientific notation' => '1e3',
]);
