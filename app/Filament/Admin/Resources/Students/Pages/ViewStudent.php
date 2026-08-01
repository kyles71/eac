<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Pages;

use App\Enums\StopLightColor;
use App\Enums\StudentNoteType;
use App\Filament\Actions\StudentContactActionGroup;
use App\Filament\Admin\Resources\StaffNotes\Schemas\StaffNoteForm;
use App\Filament\Admin\Resources\Students\StudentResource;
use App\Models\StaffNote;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\User;
use App\Services\StudentNotesService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

final class ViewStudent extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = StudentResource::class;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedSchema::make('infolist'),
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Notes & Communications')
            ->records(fn (
                ?string $sortColumn,
                ?string $sortDirection,
                ?string $search,
                array $filters,
                int $page,
                int $recordsPerPage,
            ): LengthAwarePaginator => $this->noteRecords(
                sortColumn: $sortColumn,
                sortDirection: $sortDirection,
                search: $search,
                type: $filters['type']['value'] ?? null,
                page: $page,
                recordsPerPage: $recordsPerPage,
            ))
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StudentNoteType::from($state)->getLabel())
                    ->color(fn (string $state): string => StudentNoteType::from($state)->getColor())
                    ->sortable(),
                TextColumn::make('date')
                    ->placeholder('Date unavailable')
                    ->sortable(),
                TextColumn::make('event')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('author')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('stop_light_color')
                    ->label('Color')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => StopLightColor::tryFrom((string) $state)?->getLabel())
                    ->color(fn (?string $state): string => StopLightColor::tryFrom((string) $state)?->getColor() ?? 'gray')
                    ->placeholder('-'),
                TextColumn::make('note')
                    ->wrap()
                    ->lineClamp(3),
                TextColumn::make('details')
                    ->label('Documents / Recipients')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(StudentNoteType::class),
            ])
            ->headerActions([
                $this->createStaffNoteAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->viewNoteAction(),
                    $this->editStaffNoteAction(),
                    $this->deleteStaffNoteAction(),
                ]),
            ], RecordActionsPosition::BeforeCells)
            ->searchable()
            ->defaultSort('date', 'desc')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50]);
    }

    protected function getHeaderActions(): array
    {
        return [
            StudentContactActionGroup::make(fn (): Student => $this->student()),
            EditAction::make(),
        ];
    }

    private function createStaffNoteAction(): CreateAction
    {
        return CreateAction::make('createStaffNote')
            ->label('Add staff note')
            ->model(StaffNote::class)
            ->modelLabel('staff note')
            ->relationship(fn () => $this->student()->staffNotes())
            ->authorize('create')
            ->schema(fn (Schema $schema): Schema => StaffNoteForm::configure($schema))
            ->mutateDataUsing(function (array $data): array {
                $data['author_id'] = $this->user()->id;

                return $data;
            })
            ->createAnother(false)
            ->after(fn () => $this->resetTable());
    }

    private function viewNoteAction(): Action
    {
        return Action::make('viewNote')
            ->label('View')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (array $record): bool => $this->canViewNote($record))
            ->modalHeading(fn (array $record): string => StudentNoteType::from($record['type'])->getLabel())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => StudentNoteType::from($state)->getLabel())
                            ->color(fn (string $state): string => StudentNoteType::from($state)->getColor()),
                        TextEntry::make('date')
                            ->placeholder('Date unavailable'),
                        TextEntry::make('event')
                            ->placeholder('No event selected'),
                        TextEntry::make('author')
                            ->placeholder('Not recorded'),
                        TextEntry::make('stop_light_color')
                            ->label('Stop Light Color')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): ?string => StopLightColor::tryFrom((string) $state)?->getLabel())
                            ->color(fn (?string $state): string => StopLightColor::tryFrom((string) $state)?->getColor() ?? 'gray')
                            ->visible(fn (array $record): bool => filled($record['stop_light_color'] ?? null)),
                        TextEntry::make('queued_at')
                            ->label('Queued')
                            ->visible(fn (array $record): bool => filled($record['queued_at'] ?? null)),
                        TextEntry::make('note')
                            ->columnSpanFull(),
                        TextEntry::make('recipient_emails')
                            ->label('Recipients')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull()
                            ->visible(fn (array $record): bool => filled($record['recipient_emails'] ?? [])),
                        RepeatableEntry::make('documents')
                            ->table([
                                TableColumn::make('File'),
                                TableColumn::make('Size'),
                                TableColumn::make('Added'),
                            ])
                            ->schema([
                                TextEntry::make('file_name')
                                    ->icon(Heroicon::OutlinedArrowDownTray)
                                    ->url(fn (Get $get): string => (string) $get('url')),
                                TextEntry::make('size'),
                                TextEntry::make('added'),
                            ])
                            ->contained(false)
                            ->columnSpanFull()
                            ->visible(fn (array $record): bool => filled($record['documents'] ?? [])),
                    ]),
            ]);
    }

    private function editStaffNoteAction(): Action
    {
        return Action::make('editStaffNote')
            ->label('Edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (array $record): bool => $this->canUpdateStaffNote($record))
            ->mountUsing(function (array $record, Schema $schema): void {
                $note = $this->staffNote($record);

                Gate::authorize('update', $note);
                $schema->fill($note->attributesToArray());
            })
            ->schema(fn (array $record, Schema $schema): Schema => StaffNoteForm::configure(
                $schema->model($this->staffNote($record)),
            ))
            ->action(function (array $data, array $record, Schema $schema): void {
                $note = $this->staffNote($record);

                Gate::authorize('update', $note);
                $note->update($data);
                $schema->model($note)->saveRelationships();
                $this->resetTable();

                Notification::make()
                    ->title('Staff note updated')
                    ->success()
                    ->send();
            });
    }

    private function deleteStaffNoteAction(): Action
    {
        return Action::make('deleteStaffNote')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (array $record): bool => $this->canDeleteStaffNote($record))
            ->action(function (array $record): void {
                $note = $this->staffNote($record);

                Gate::authorize('delete', $note);
                $note->delete();
                $this->resetTable();

                Notification::make()
                    ->title('Staff note deleted')
                    ->success()
                    ->send();
            });
    }

    private function noteRecords(
        ?string $sortColumn,
        ?string $sortDirection,
        ?string $search,
        mixed $type,
        int $page,
        int $recordsPerPage,
    ): LengthAwarePaginator {
        $records = app(StudentNotesService::class)
            ->records($this->student(), $this->user())
            ->when(
                is_string($type) && StudentNoteType::tryFrom($type) instanceof StudentNoteType,
                fn (Collection $records): Collection => $records->where('type', $type),
            )
            ->when(filled($search), function (Collection $records) use ($search): Collection {
                $needle = Str::lower((string) $search);

                return $records->filter(fn (array $record): bool => Str::contains(
                    Str::lower(implode(' ', array_filter([
                        StudentNoteType::from($record['type'])->getLabel(),
                        $record['date'],
                        $record['event'],
                        $record['author'],
                        $record['note'],
                        $record['stop_light_color'],
                    ]))),
                    $needle,
                ));
            });

        if (filled($sortColumn)) {
            $records = $records->sortBy(
                fn (array $record): mixed => match ($sortColumn) {
                    'date' => $record['sort_at'],
                    'type' => StudentNoteType::from($record['type'])->getLabel(),
                    default => $record[$sortColumn] ?? null,
                },
                SORT_NATURAL | SORT_FLAG_CASE,
                $sortDirection === 'desc',
            );
        }

        return new LengthAwarePaginator(
            items: $records->forPage($page, $recordsPerPage),
            total: $records->count(),
            perPage: $recordsPerPage,
            currentPage: $page,
        );
    }

    /** @param array<string, mixed> $record */
    private function canViewNote(array $record): bool
    {
        return match ($record['source'] ?? null) {
            'attendance' => true,
            'staff_note' => ($note = $this->findStaffNote($record)) instanceof StaffNote
                && Gate::allows('view', $note),
            'student_communication' => ($communication = $this->findCommunication($record)) instanceof StudentCommunication
                && Gate::allows('view', $communication),
            default => false,
        };
    }

    /** @param array<string, mixed> $record */
    private function canUpdateStaffNote(array $record): bool
    {
        $note = $this->findStaffNote($record);

        return $note instanceof StaffNote && Gate::allows('update', $note);
    }

    /** @param array<string, mixed> $record */
    private function canDeleteStaffNote(array $record): bool
    {
        $note = $this->findStaffNote($record);

        return $note instanceof StaffNote && Gate::allows('delete', $note);
    }

    /** @param array<string, mixed> $record */
    private function staffNote(array $record): StaffNote
    {
        $note = $this->findStaffNote($record);

        abort_unless($note instanceof StaffNote, 404);

        return $note;
    }

    /** @param array<string, mixed> $record */
    private function findStaffNote(array $record): ?StaffNote
    {
        if (($record['source'] ?? null) !== 'staff_note') {
            return null;
        }

        return $this->student()
            ->staffNotes()
            ->find((int) ($record['source_id'] ?? 0));
    }

    /** @param array<string, mixed> $record */
    private function findCommunication(array $record): ?StudentCommunication
    {
        if (($record['source'] ?? null) !== 'student_communication') {
            return null;
        }

        return $this->student()
            ->studentCommunications()
            ->find((int) ($record['source_id'] ?? 0));
    }

    private function student(): Student
    {
        $record = $this->getRecord();

        if (! $record instanceof Student) {
            throw new LogicException('The student record is unavailable.');
        }

        return $record;
    }

    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('Student notes require an authenticated user.');
        }

        return $user;
    }
}
