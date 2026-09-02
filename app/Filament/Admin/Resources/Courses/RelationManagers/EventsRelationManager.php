<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\RelationManagers;

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Admin\Resources\Events\Tables\EventsTable;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use App\Models\Course;
use App\Models\Event;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use LogicException;

final class EventsRelationManager extends RelationManager
{
    use HasRecurring;

    protected static string $relationship = 'events';

    public function isReadOnly(): bool
    {
        $user = auth()->user();

        return $this->course()->is_private
            && (! $user instanceof User || ! $user->hasAnyRole(['owner', 'super_admin']));
    }

    public function form(Schema $schema): Schema
    {
        return EventForm::configure($schema, $this->course()->id);
    }

    public function table(Table $table): Table
    {
        return EventsTable::configure($table)
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->prepRecurringData($data))
                    ->after(function (array $data): void {
                        $this->createRecurring($data, $this->repeat_through, $this->repeat_frequency, function (array $data): void {
                            $record = new Event($data);

                            $this->course()->events()->save($record);
                            app(ManageEventTeacherAssignments::class)->initializeCourseEvent($record);
                        });
                    }),
            ]);
    }

    private function course(): Course
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof Course) {
            throw new LogicException('Course event relation managers require a course owner record.');
        }

        return $record;
    }
}
