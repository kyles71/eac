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
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'active' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->active($now)),
            'future' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->future($now)),
            'past' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereNotNull('student_id')
                    ->past($now)),
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
