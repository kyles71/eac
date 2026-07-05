<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseSemester;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Enrollment extends Model
{
    /** @use HasFactory<\Database\Factories\EnrollmentFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'course_id' => 'integer',
        'user_id' => 'integer',
        'order_item_id' => 'integer',
        'student_id' => 'integer',
        'assignment_reminder_sent_at' => 'datetime',
    ];

    public static function applyOpenConstraint(Builder $query): Builder
    {
        return $query->whereNull('student_id');
    }

    public static function applyActiveConstraint(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= Carbon::now();

        return $query->whereNotNull('student_id')
            ->whereHas(
                'course',
                fn (Builder $query): Builder => self::applyCourseNotConcludedConstraint(
                    $query->whereHas(
                        'events',
                        fn (Builder $query): Builder => $query->where('start_time', '<', $date)
                    ),
                    $date,
                )
            );
    }

    public static function applyFutureConstraint(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= Carbon::now();

        return $query->whereNotNull('student_id')
            ->whereHas('course', function (Builder $query) use ($date): void {
                $query
                    ->whereHas(
                        'events',
                        fn (Builder $query): Builder => $query->where('start_time', '>', $date)
                    )
                    ->whereDoesntHave(
                        'events',
                        fn (Builder $query): Builder => $query->where('start_time', '<=', $date)
                    );
            });
    }

    public static function applyPastConstraint(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= Carbon::now();

        return $query->whereHas('course', fn (Builder $query): Builder => self::applyCourseConcludedConstraint($query, $date));
    }

    public static function applySemesterConstraint(Builder $query, CourseSemester $semester, ?Carbon $date = null): Builder
    {
        $date ??= Carbon::now();

        return $query->whereHas(
            'course',
            fn (Builder $query): Builder => self::applyCourseNotConcludedConstraint(
                $query->where('semester', $semester->value),
                $date,
            )
        );
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<Enrollment> $query */
    public function scopeOpen(Builder $query): void
    {
        self::applyOpenConstraint($query);
    }

    /** @param Builder<Enrollment> $query */
    public function scopeActive(Builder $query, ?Carbon $date = null): void
    {
        self::applyActiveConstraint($query, $date);
    }

    /** @param Builder<Enrollment> $query */
    public function scopeFuture(Builder $query, ?Carbon $date = null): void
    {
        self::applyFutureConstraint($query, $date);
    }

    /** @param Builder<Enrollment> $query */
    public function scopePast(Builder $query, ?Carbon $date = null): void
    {
        self::applyPastConstraint($query, $date);
    }

    /** @param Builder<Enrollment> $query */
    public function scopeSemester(Builder $query, CourseSemester $semester, ?Carbon $date = null): void
    {
        self::applySemesterConstraint($query, $semester, $date);
    }

    private static function applyCourseConcludedConstraint(Builder $query, Carbon $date): Builder
    {
        return $query->where(function (Builder $query) use ($date): void {
            $query
                ->whereDoesntHave('events')
                ->orWhere(function (Builder $query) use ($date): void {
                    $query
                        ->whereHas('events')
                        ->whereDoesntHave(
                            'events',
                            fn (Builder $query): Builder => self::applyEventNotPassedConstraint($query, $date)
                        );
                });
        });
    }

    private static function applyCourseNotConcludedConstraint(Builder $query, Carbon $date): Builder
    {
        return $query->whereHas(
            'events',
            fn (Builder $query): Builder => self::applyEventNotPassedConstraint($query, $date)
        );
    }

    private static function applyEventNotPassedConstraint(Builder $query, Carbon $date): Builder
    {
        return $query->where(function (Builder $query) use ($date): void {
            $query
                ->where('end_time', '>=', $date)
                ->orWhere(function (Builder $query) use ($date): void {
                    $query
                        ->whereNull('end_time')
                        ->where('start_time', '>=', $date);
                });
        });
    }
}
