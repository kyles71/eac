<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\RelationManagers;

use App\Actions\Forms\PublishFormVersion;
use App\Enums\FormVersionStatus;
use App\Filament\Admin\Resources\Forms\Schemas\FormVersionForm;
use App\Forms\FormSchemaCompiler;
use App\Forms\FormVersionComparator;
use App\Models\Form;
use App\Models\FormVersion;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

final class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return FormVersionForm::configure($schema, $this->formRecord()->purpose);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (FormVersion $record): string => $record->versionLabel())
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('status')->badge(),
                IconColumn::make('requires_signature')->label('Signature')->boolean(),
                TextColumn::make('valid_until')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('published_at')->dateTime()->placeholder('-')->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Create Draft Version')
                    ->visible(fn (): bool => ! $this->formRecord()->draftVersion()->exists())
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'version' => ((int) $this->formRecord()->versions()->max('version')) + 1,
                        'status' => FormVersionStatus::Draft,
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (FormVersion $record): bool => ! $record->isPublished()),
                Action::make('publish')
                    ->color('success')
                    ->icon('heroicon-o-paper-airplane')
                    ->authorize('update')
                    ->visible(fn (FormVersion $record): bool => ! $record->isPublished())
                    ->schema([
                        \Filament\Forms\Components\Toggle::make('require_completed_again')
                            ->label('Require currently completed assignments to submit this version')
                            ->default(false),
                    ])
                    ->action(function (FormVersion $record, array $data): void {
                        app(PublishFormVersion::class)->handle(
                            $record,
                            auth()->user(),
                            (bool) ($data['require_completed_again'] ?? false),
                        );

                        Notification::make()->title('Form version published')->success()->send();
                    }),
                Action::make('compare')
                    ->icon('heroicon-o-arrows-right-left')
                    ->authorize('view')
                    ->visible(fn (FormVersion $record): bool => $record->form
                        ->versions()
                        ->where('version', '<', $record->version)
                        ->exists())
                    ->modalHeading(fn (FormVersion $record): string => "Compare {$record->versionLabel()}")
                    ->schema(function (FormVersion $record): array {
                        $previous = $this->previousVersion($record);

                        return [
                            Grid::make(2)
                                ->schema([
                                    Section::make($previous->versionLabel())
                                        ->columnSpan(1)
                                        ->schema(app(FormSchemaCompiler::class)->components($previous, disabled: true)),
                                    Section::make($record->versionLabel())
                                        ->columnSpan(1)
                                        ->schema(app(FormSchemaCompiler::class)->components($record, disabled: true)),
                                ]),
                        ];
                    })
                    ->modalContent(function (FormVersion $record) {
                        $previous = $this->previousVersion($record);

                        return view('filament.admin.forms.version-compare', [
                            'left' => $previous,
                            'right' => $record,
                            'comparison' => app(FormVersionComparator::class)->compare($previous, $record),
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                DeleteAction::make()
                    ->visible(fn (FormVersion $record): bool => ! $record->isPublished()),
            ]);
    }

    private function formRecord(): Form
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof Form) {
            throw new LogicException('The version relation manager requires a Form owner record.');
        }

        return $record;
    }

    private function previousVersion(FormVersion $version): FormVersion
    {
        return FormVersion::query()
            ->where('form_id', $version->form_id)
            ->where('version', '<', $version->version)
            ->latest('version')
            ->firstOrFail();
    }
}
