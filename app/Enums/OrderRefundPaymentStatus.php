<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderRefundPaymentStatus: string
{
    case Processing = 'processing';
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public static function fromStripe(?string $status): self
    {
        return match ($status) {
            self::Succeeded->value => self::Succeeded,
            self::Failed->value => self::Failed,
            self::Canceled->value => self::Canceled,
            self::RequiresAction->value => self::RequiresAction,
            default => self::Pending,
        };
    }

    public function reservesFunds(): bool
    {
        return ! in_array($this, [self::Failed, self::Canceled], true);
    }

    public function canTransitionTo(self $status): bool
    {
        return $status->precedence() >= $this->precedence();
    }

    private function precedence(): int
    {
        return match ($this) {
            self::Processing => 0,
            self::Pending, self::RequiresAction => 1,
            self::Failed, self::Canceled => 2,
            self::Succeeded => 3,
        };
    }
}
