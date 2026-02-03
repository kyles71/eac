<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\RelationManagers;

use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Admin\Resources\Events\Tables\EventsTable;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

final class EventsRelationManager extends RelationManager
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
                    ->after(function (array $data, CreateAction $action): void {
                        $this->createRecurring($data, $this->repeat_through, $this->repeat_frequency, function (array $data) use ($action): void {
                            $table = $this->getTable();
                            $relationship = $table->getRelationship();
                            $model = $action->getModel();
                            $record = new $model($data);
                            $relationship->save($record);
                        });
                    }),
            ]);
    }
}
