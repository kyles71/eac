<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LegalDocuments\Tables;

use App\Models\LegalDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
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
            ->recordActions([
                Action::make('publishVersion')
                    ->label('Publish Version')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->authorize('publish')
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
}
