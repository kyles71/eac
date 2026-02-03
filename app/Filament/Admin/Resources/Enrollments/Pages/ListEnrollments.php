<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Enrollments\Pages;

use App\Filament\Admin\Resources\Enrollments\EnrollmentResource;
use Carbon\Carbon;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListEnrollments extends ListRecords
{
    protected static string $resource = EnrollmentResource::class;

    public function getTabs(): array
    {
        $now = Carbon::now();

        return [
            'open' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('student_id')),
            'active' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->join('courses', 'courses.id', '=', 'enrollments.course_id')
                    ->join('events', 'events.course_id', '=', 'courses.id')
                    ->whereNotNull('enrollments.student_id')
                    ->where('courses.start_time', '<', $now)
                    ->where('events.start_time', '>', $now)
                    ->groupBy('courses.id')),
            'future' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('student_id')
                    ->whereRelation('course', 'start_time', '>', $now)),
            'past' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->select('enrollments.*')
                    ->join('courses', 'courses.id', '=', 'enrollments.course_id')
                    ->leftJoin('events', function ($join) use ($now): void {
                        $join->on('events.course_id', '=', 'enrollments.id')
                            ->where('events.start_time', '>', $now);
                    })
                    ->whereNotNull('enrollments.student_id')
                    ->where('courses.start_time', '<', $now)
                    ->whereNull('events.id')
                    ->groupBy('enrollments.id')),
            'all' => Tab::make(),
            // ->modifyQueryUsing(fn (Builder $query) => $query->where('active', false)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
