<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LegalDocuments\Tables;

use App\Models\LegalDocument;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class LegalDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->label('Key')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latestPublishedVersion.version')
                    ->label('Current Version')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "Version {$state}" : 'None'),
                TextColumn::make('latestPublishedVersion.published_at')
                    ->label('Published')
                    ->dateTime()
                    ->placeholder('Not published'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('New Legal Document')
                    ->icon(Heroicon::OutlinedPlus)
                    ->schema(self::documentSchema(includeKey: true))
                    ->modalSubmitActionLabel('Create Document')
                    ->successNotificationTitle('Legal document created'),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(self::documentSchema(includeKey: false))
                    ->modalSubmitActionLabel('Save Document')
                    ->successNotificationTitle('Legal document updated'),
                Action::make('publishVersion')
                    ->label('Publish Version')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->schema(fn (LegalDocument $record): array => [
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->default($record->name),
                        RichEditor::make('content')
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->modalSubmitActionLabel('Publish Version')
                    ->action(function (LegalDocument $record, array $data): void {
                        $record->publishVersion($data['title'], $data['content']);

                        Notification::make()
                            ->title('Document version published')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private static function documentSchema(bool $includeKey): array
    {
        return [
            ...($includeKey ? [
                TextInput::make('key')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Stable code-facing key, such as payment_plan_terms.'),
            ] : []),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->rows(3)
                ->maxLength(1000)
                ->columnSpanFull(),
        ];
    }
}
