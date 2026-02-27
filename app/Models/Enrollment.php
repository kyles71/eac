<?php

declare(strict_types=1);

namespace App\Models;

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

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

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

        $query->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->join('events', 'events.course_id', '=', 'courses.id')
            ->whereNotNull('enrollments.student_id')
            ->where('courses.start_time', '<', $date)
            ->where('events.start_time', '>', $date)
            ->groupBy('courses.id');
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

        $query->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->leftJoin('events', function ($join) use ($date): void {
                $join->on('events.course_id', '=', 'enrollments.id')
                    ->where('events.start_time', '>', $date);
            })
            ->whereNotNull('enrollments.student_id')
            ->where('courses.start_time', '<', $date)
            ->whereNull('events.id')
            ->groupBy('enrollments.id');
    }
}
