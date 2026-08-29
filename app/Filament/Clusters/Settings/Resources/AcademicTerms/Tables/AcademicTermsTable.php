<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\AcademicTerms\Tables;

use App\Filament\Clusters\Settings\Resources\AcademicTerms\AcademicTermResource;
use App\Models\AcademicTerm;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class AcademicTermsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('academicYear.display_name')
                    ->label('Academic Year')
                    ->sortable(),
                TextColumn::make('display_name')
                    ->label('Term')
                    ->state(fn (AcademicTerm $record): string => $record->display_name),
                TextColumn::make('starts_on')
                    ->label('Starts On')
                    ->date()
                    ->sortable(),
                TextColumn::make('ends_on')
                    ->label('Ends On')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (AcademicTerm $record): string => $record->status())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Current' => 'success',
                        'Upcoming' => 'info',
                        default => 'gray',
                    }),
                IconColumn::make('uses_default_dates')
                    ->label('Recurring Defaults')
                    ->boolean(),
                TextColumn::make('courses_count')
                    ->label('Courses')
                    ->counts('courses')
                    ->sortable(),
                TextColumn::make('target_enrollments')
                    ->label('Target')
                    ->numeric()
                    ->placeholder('Not set')
                    ->toggleable(),
                TextColumn::make('stretch_goal_enrollments')
                    ->label('Stretch Goal')
                    ->numeric()
                    ->placeholder('Not set')
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->mutateDataUsing(fn (array $data): array => AcademicTermResource::prepareFormData($data)),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->defaultSort('starts_on', 'desc');
    }
}
