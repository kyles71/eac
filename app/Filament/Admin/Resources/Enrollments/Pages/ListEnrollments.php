<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Enrollments\Pages;

use App\Filament\Admin\Resources\Enrollments\EnrollmentResource;
use App\Models\Enrollment;
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
                ->modifyQueryUsing(fn (Builder $query): Builder => Enrollment::applyOpenConstraint($query)),
            'active' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => Enrollment::applyActiveConstraint($query, $now)),
            'future' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => Enrollment::applyFutureConstraint($query, $now)),
            'past' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => Enrollment::applyPastConstraint(
                    $query->whereNotNull('student_id'),
                    $now,
                )),
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
