<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CourseHolds\Pages;

use App\Filament\Admin\Resources\CourseHolds\CourseHoldResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListCourseHolds extends ListRecords
{
    protected static string $resource = CourseHoldResource::class;

    public function getTabs(): array
    {
        return [
            'active' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->current()),
            'purchased' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereHas('seats')
                    ->whereDoesntHave('seats', fn (Builder $query): Builder => $query->whereDoesntHave('enrollment'))),
            'expired' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('expires_at', '<=', now())
                    ->whereHas('seats', fn (Builder $query): Builder => $query->whereDoesntHave('enrollment'))),
            'all' => Tab::make(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CourseHoldResource::createAction(),
        ];
    }
}
