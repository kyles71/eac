<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseSemester;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Scope;
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

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('student_id');
    }

    #[Scope]
    protected function active(Builder $query, ?Carbon $date = null): void
    {
        if (! $date instanceof Carbon) {
            $date = Carbon::now();
        }

        $query->whereNotNull('student_id')
            ->whereHas(
                'course',
                fn (Builder $query): Builder => $query
                    ->where('start_time', '<', $date)
                    ->notConcluded($date)
            );
    }

    #[Scope]
    protected function future(Builder $query, ?Carbon $date = null): void
    {
        if (! $date instanceof Carbon) {
            $date = Carbon::now();
        }

        $query->whereNotNull('student_id')
            ->whereRelation('course', 'start_time', '>', $date);
    }

    #[Scope]
    protected function past(Builder $query, ?Carbon $date = null): void
    {
        if (! $date instanceof Carbon) {
            $date = Carbon::now();
        }

        $query->whereHas('course', fn (Builder $query): Builder => $query->concluded($date));
    }

    #[Scope]
    protected function semester(Builder $query, CourseSemester $semester, ?Carbon $date = null): void
    {
        if (! $date instanceof Carbon) {
            $date = Carbon::now();
        }

        $query->whereHas(
            'course',
            fn (Builder $query): Builder => $query
                ->where('semester', $semester->value)
                ->notConcluded($date)
        );
    }
}
