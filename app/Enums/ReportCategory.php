<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
use Filament\Support\Contracts\HasLabel;

enum ReportCategory: string implements HasLabel
{
    case Enrollment = 'enrollment';
    case Instructor = 'instructor';
    case Finance = 'finance';
    case Gear = 'gear';
    case Costume = 'costume';

    public function getLabel(): string
    {
        return match ($this) {
            self::Enrollment => 'Enrollment Reports',
            self::Instructor => 'Instructor Reports',
            self::Finance => 'Finance Reports',
            self::Gear => 'Gear Reports',
            self::Costume => 'Costume Reports',
        };
    }

    public function canView(User $user): bool
    {
        foreach (ReportKey::cases() as $report) {
            if ($report->category() === $this && $report->canView($user)) {
                return true;
            }
        }

        foreach (ReportWidgetKey::cases() as $widget) {
            if ($widget->category() === $this && $widget->canView($user)) {
                return true;
            }
        }

        return false;
    }
}
