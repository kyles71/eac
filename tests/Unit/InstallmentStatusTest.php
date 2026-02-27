<?php

declare(strict_types=1);

use App\Enums\InstallmentStatus;

it('has correct labels', function (InstallmentStatus $status, string $label) {
    expect($status->getLabel())->toBe($label);
})->with([
    [InstallmentStatus::Pending, 'Pending'],
    [InstallmentStatus::Paid, 'Paid'],
    [InstallmentStatus::Failed, 'Failed'],
    [InstallmentStatus::Overdue, 'Overdue'],
]);
