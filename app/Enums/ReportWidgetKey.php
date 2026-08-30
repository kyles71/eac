<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;

enum ReportWidgetKey: string
{
    case EnrollmentOverview = 'enrollment-overview';
    case EnrollmentCapacityMetrics = 'enrollment-capacity-metrics';
    case InstructorOverview = 'instructor-overview';

    /** @return array<string, string> */
    public static function dedicatedPermissionOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $widget): bool => $widget->hasDedicatedPermission())
            ->mapWithKeys(fn (self $widget): array => [
                $widget->permission() => 'View '.$widget->label(),
            ])
            ->all();
    }

    public function category(): ReportCategory
    {
        return match ($this) {
            self::EnrollmentOverview,
            self::EnrollmentCapacityMetrics => ReportCategory::Enrollment,
            self::InstructorOverview => ReportCategory::Instructor,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EnrollmentOverview => 'Enrollment Overview Widget',
            self::EnrollmentCapacityMetrics => 'Enrollment Capacity Metrics Widget',
            self::InstructorOverview => 'Instructor Overview Widget',
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::EnrollmentOverview => ReportKey::TotalEnrollmentsByClass->permission(),
            self::EnrollmentCapacityMetrics => 'EnrollmentCapacityMetricsWidget:Reports',
            self::InstructorOverview => 'InstructorOverviewWidget:Reports',
        };
    }

    public function hasDedicatedPermission(): bool
    {
        return $this !== self::EnrollmentOverview;
    }

    public function canView(User $user): bool
    {
        return $user->can($this->permission());
    }

    public function availableToTeachersByDefault(): bool
    {
        return true;
    }
}
