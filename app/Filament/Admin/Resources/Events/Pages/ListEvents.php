<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

final class ListEvents extends ListRecords
{
    use HasRecurring;

    protected static string $resource = EventResource::class;

    #[On('event-substitution-updated')]
    public function refreshEventsTable(): void
    {
        $this->flushCachedTableRecords();
    }

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
}
