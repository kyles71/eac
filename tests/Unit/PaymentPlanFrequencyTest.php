<?php

declare(strict_types=1);

use App\Enums\PaymentPlanFrequency;

it('has correct labels', function (PaymentPlanFrequency $frequency, string $label) {
    expect($frequency->getLabel())->toBe($label);
})->with([
    [PaymentPlanFrequency::Daily, 'Daily'],
    [PaymentPlanFrequency::Weekly, 'Weekly'],
    [PaymentPlanFrequency::Biweekly, 'Biweekly'],
    [PaymentPlanFrequency::Monthly, 'Monthly'],
]);

it('has correct interval days', function (PaymentPlanFrequency $frequency, int $days) {
    expect($frequency->intervalDays())->toBe($days);
})->with([
    [PaymentPlanFrequency::Daily, 1],
    [PaymentPlanFrequency::Weekly, 7],
    [PaymentPlanFrequency::Biweekly, 14],
    [PaymentPlanFrequency::Monthly, 30],
]);

it('hides daily frequency in production options', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(PaymentPlanFrequency::optionsForEnvironment())
        ->not->toHaveKey(PaymentPlanFrequency::Daily->value)
        ->toHaveKey(PaymentPlanFrequency::Weekly->value);
});
