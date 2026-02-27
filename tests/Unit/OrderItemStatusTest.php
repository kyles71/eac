<?php

declare(strict_types=1);

use App\Enums\OrderItemStatus;

it('has correct labels', function (OrderItemStatus $status, string $label) {
    expect($status->getLabel())->toBe($label);
})->with([
    [OrderItemStatus::Pending, 'Pending'],
    [OrderItemStatus::Fulfilled, 'Fulfilled'],
]);
