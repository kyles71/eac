<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BoardItems\RelationManagers;

use App\Models\BoardItem;
use App\Models\BoardItemActivity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Activity';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof BoardItem && Gate::allows('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('actor'))
            ->columns([
                TextColumn::make('actor.full_name')
                    ->label('Person')
                    ->placeholder('System'),
                TextColumn::make('description')
                    ->state(fn (BoardItemActivity $record): string => $record->description())
                    ->wrap(),
                TextColumn::make('type')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
