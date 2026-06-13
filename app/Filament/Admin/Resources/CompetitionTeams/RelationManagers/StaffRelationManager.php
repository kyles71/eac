<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\RelationManagers;

use App\Filament\Actions\SendEmailAction;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\CompetitionTeam;
use App\Models\User;
use App\Services\CompetitionRosterService;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StaffRelationManager extends RelationManager
{
    protected static string $relationship = 'staff';

    protected static ?string $relatedResource = UserResource::class;

    public function isReadOnly(): bool
    {
        $team = $this->getOwnerRecord();

        return ! $team instanceof CompetitionTeam || $team->hasEnded();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (User $record): string => $record->getFilamentName())
            ->columns([
                TextColumn::make('first_name')
                    ->searchable(),
                TextColumn::make('last_name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->multiple()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['first_name', 'last_name', 'email'])
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => app(CompetitionRosterService::class)->applyRoleBearingScope($query)),
            ])
            ->recordActions([
                SendEmailAction::make()
                    ->to(fn (User $record): array => [$record]),
                DetachAction::make(),
            ]);
    }
}
