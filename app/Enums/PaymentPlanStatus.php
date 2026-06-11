<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\PaymentPlan;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentPlanStatus: string implements HasColor, HasLabel
{
    case Active = 'Active';
    case Paid = 'Paid';
    case Overdue = 'Overdue';
    case PaymentFailed = 'Payment Failed';
    case Failed = 'Failed';
    case Refunded = 'Refunded';
    case Cancelled = 'Cancelled';

    public static function forPaymentPlan(PaymentPlan $paymentPlan): self
    {
        $terminalStatus = match ($paymentPlan->order?->status) {
            OrderStatus::Cancelled => self::Cancelled,
            OrderStatus::Refunded => self::Refunded,
            OrderStatus::Failed => self::Failed,
            default => null,
        };

        if ($terminalStatus !== null) {
            return $terminalStatus;
        }

        if ($paymentPlan->isFullyPaid()) {
            return self::Paid;
        }

        if ($paymentPlan->installments->contains('status', InstallmentStatus::Overdue)) {
            return self::Overdue;
        }

        if ($paymentPlan->installments->contains('status', InstallmentStatus::Failed)) {
            return self::PaymentFailed;
        }

        return self::Active;
    }

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Paid => 'success',
            self::Overdue, self::PaymentFailed, self::Failed => 'danger',
            self::Refunded, self::Cancelled => 'gray',
        };
    }
}
