<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlanTemplates\Tables;

use App\Models\PaymentPlanTemplate;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

final class PaymentPlanTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_type')
                    ->label('Product Type')
                    ->badge(),
                TextColumn::make('course_semesters')
                    ->label('Semesters')
                    ->state(fn (PaymentPlanTemplate $record): string => $record->allowedCourseSemesters() === []
                        ? 'All'
                        : implode(', ', $record->allowedCourseSemesters()))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('min_price')
                    ->label('Min Price')
                    ->moneyCents()
                    ->sortable(),
                TextColumn::make('max_price')
                    ->label('Max Price')
                    ->moneyCents()
                    ->sortable(),
                TextColumn::make('number_of_installments')
                    ->label('Installments')
                    ->sortable(),
                TextColumn::make('frequency')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->visible(fn (PaymentPlanTemplate $record): bool => ! $record->isUsed()),
                    Action::make('deactivate')
                        ->label('Deactivate')
                        ->icon(Heroicon::OutlinedArchiveBoxXMark)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Deactivate payment plan template')
                        ->modalDescription('Existing purchases keep their original payment plan terms. New purchases will no longer be able to choose this template.')
                        ->authorize(fn (PaymentPlanTemplate $record): bool => Gate::allows('deactivate', $record))
                        ->action(function (PaymentPlanTemplate $record): void {
                            $record->update(['is_active' => false]);

                            Notification::make()
                                ->title('Payment plan template deactivated')
                                ->success()
                                ->send();
                        }),
                    Action::make('reactivate')
                        ->label('Reactivate')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Reactivate payment plan template')
                        ->modalDescription('New purchases will be able to choose this template again. Existing purchases keep their original payment plan terms.')
                        ->authorize(fn (PaymentPlanTemplate $record): bool => Gate::allows('reactivate', $record))
                        ->action(function (PaymentPlanTemplate $record): void {
                            $record->update(['is_active' => true]);

                            Notification::make()
                                ->title('Payment plan template reactivated')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}
