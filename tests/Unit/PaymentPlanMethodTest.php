<?php

declare(strict_types=1);

use App\Enums\PaymentPlanMethod;

it('has correct labels', function (PaymentPlanMethod $method, string $label) {
    expect($method->getLabel())->toBe($label);
})->with([
    [PaymentPlanMethod::AutoCharge, 'Auto Charge'],
    [PaymentPlanMethod::ManualInvoice, 'Manual Invoice'],
]);
