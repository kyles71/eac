<?php

namespace App\Filament\Resources\Courses\RelationManagers;

use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Resources\Traits\HasRecurring;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    use HasRecurring;

    protected static string $relationship = 'events';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return EventForm::configure($schema, $this->getOwnerRecord()->id);
    }

    public function table(Table $table): Table
    {
        return EventsTable::configure($table)
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->prepRecurringData($data))
                    ->after(function (array $data, CreateAction $action) {
                        $this->createRecurring($data, $this->repeat_through, $this->repeat_frequency, function(array $data) use ($action) {
                            $table = $this->getTable();
                            $relationship = $table->getRelationship();
                            $model = $action->getModel();
                            $record = new $model($data);
                            $relationship->save($record);
                        });
                    })
            ]);
    }
}
