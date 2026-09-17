<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use App\Models\Event;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
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

    public function getTabs(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->hasCourseRestrictedAdminAccess()) {
            return [];
        }

        return [
            'all' => Tab::make('All Events'),
            'mine' => Tab::make('My Events')
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => Event::applyAdminUserViewConstraint($query, $user),
                ),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => $this->prepRecurringData($data))
                ->after(function (array $data, CreateAction $action): void {
                    $rawData = $action->getRawData();
                    $teacherIds = is_array($rawData['teacher_ids'] ?? null)
                        ? $rawData['teacher_ids']
                        : [];
                    $scheduleConflictOverrideBy = EventForm::scheduleConflictOverrideActor($rawData);

                    $this->createRecurring($data, $this->repeat_through, $this->repeat_frequency, function (array $data) use ($action, $scheduleConflictOverrideBy, $teacherIds): void {
                        $model = $action->getModel();
                        $record = new $model($data);
                        $record->save();

                        $primaryEvent = $action->getRecord();

                        if (! $record instanceof Event || ! $primaryEvent instanceof Event) {
                            return;
                        }

                        EventForm::assignTeachers(
                            event: $record,
                            teacherIds: $teacherIds === []
                                ? $primaryEvent->teachers()->pluck('users.id')->all()
                                : $teacherIds,
                            scheduleConflictOverrideBy: $scheduleConflictOverrideBy,
                        );
                    });
                }),
        ];
    }
}
