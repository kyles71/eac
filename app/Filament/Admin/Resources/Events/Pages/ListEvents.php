<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Filament\Admin\Resources\Events\EventResource;
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
                    $this->createRecurring($data, $this->repeat_through, $this->repeat_frequency, function (array $data) use ($action): void {
                        $model = $action->getModel();
                        $record = new $model($data);
                        $record->save();

                        $primaryEvent = $action->getRecord();

                        if (! $record instanceof Event || ! $primaryEvent instanceof Event) {
                            return;
                        }

                        if ($record->course_id !== null) {
                            app(ManageEventTeacherAssignments::class)->initializeCourseEvent($record);

                            return;
                        }

                        app(ManageEventTeacherAssignments::class)->assignCustom(
                            $record,
                            $primaryEvent->teachers()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all(),
                        );
                    });
                }),
        ];
    }
}
