<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\RelationManagers;

use App\Filament\Actions\SendEmailAction;
use App\Filament\Admin\Resources\Students\StudentResource;
use App\Models\CompetitionTeam;
use App\Models\Student;
use Carbon\CarbonInterface;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    protected static ?string $relatedResource = StudentResource::class;

    public function isReadOnly(): bool
    {
        $team = $this->getOwnerRecord();

        return ! $team instanceof CompetitionTeam || $team->hasEnded();
    }

    public function table(Table $table): Table
    {
        $januaryFirst = $this->januaryFirst();

        return $table
            ->recordTitleAttribute('fullName')
            ->columns([
                TextColumn::make('first_name')
                    ->searchable(),
                TextColumn::make('last_name')
                    ->searchable(),
                TextColumn::make('user.full_name')
                    ->label('Parent / User')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('age_as_of_january_first')
                    ->label('Age As of Jan 1')
                    ->state(fn (Student $record): int => $record->ageOn($januaryFirst)),
            ])
            ->headerActions([
                AttachAction::make()
                    ->multiple()
                    ->recordSelectSearchColumns(['first_name', 'last_name']),
            ])
            ->recordActions([
                SendEmailAction::make()
                    ->to(fn (Student $record): array => [$record]),
                DetachAction::make(),
            ]);
    }

    private function januaryFirst(): CarbonInterface
    {
        $team = $this->getOwnerRecord();

        if (! $team instanceof CompetitionTeam) {
            return now()->startOfYear();
        }

        $team->loadMissing('season');

        return $team->season->januaryFirst();
    }
}
