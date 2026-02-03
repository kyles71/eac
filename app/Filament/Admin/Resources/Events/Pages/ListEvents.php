<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

final class ListEvents extends ListRecords
{
    use HasRecurring;

    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => $this->prepRecurringData($data))
                ->after(function (array $data, CreateAction $action): void {
                    $this->createRecurring($data, $this->repeat_through, $this->repeat_frequency, function (array $data) use ($action): void {
                        $model = $action->getModel();
                        $record = new $model($data);
                        $record->save();
                    });
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data['course_id'] = $data['course_id'] ?: null;
        $new_data = $this->prepRecurringData($data);
        $record = parent::handleRecordCreation($new_data['data']);
        $this->createRecurring($new_data['data'], $new_data['repeat_through'], $new_data['repeat_frequency'], function ($data): void {
            parent::handleRecordCreation($data);
        });

        return $record;
    }
}
