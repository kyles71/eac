<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Pages;

use App\Enums\AttendanceStatus;
use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Services\EventAttendanceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use LogicException;

final class CourseAttendance extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = CourseResource::class;

    protected static ?string $title = 'Course Attendance';

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->attendanceRosterQuery())
            ->description('Attendance notes are private. Only owners, staff, and teachers can view them; parents and students cannot.')
            ->searchable(false)
            ->reorderableColumns(false)
            ->recordTitleAttribute('student.full_name')
            ->columns([
                TextColumn::make('student.full_name')
                    ->label('Student')
                    ->searchable(['students.first_name', 'students.last_name'])
                    ->toggleable(false)
                    ->sortable(false),
                ...$this->eventAttendanceColumns(),
            ])
            ->paginated(false);
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        Gate::authorize('viewAttendance', $this->course());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToCourse')
                ->label('Back to Course')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => CourseResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * @return Builder<Enrollment>
     */
    private function attendanceRosterQuery(): Builder
    {
        return $this->attendance()->courseRosterQuery($this->course()->id);
    }

    /**
     * @return array<int, ColumnGroup>
     */
    private function eventAttendanceColumns(): array
    {
        return $this->courseEvents()
            ->map(fn (Event $event): ColumnGroup => ColumnGroup::make($this->eventColumnLabel($event), [
                SelectColumn::make("attendance_{$event->id}")
                    ->label('Status')
                    ->disabled(fn (): bool => Gate::denies('updateAttendance', $event))
                    ->options(AttendanceStatus::class)
                    ->placeholder('Not recorded')
                    ->selectablePlaceholder()
                    ->rules(fn (): array => [new Enum(AttendanceStatus::class)])
                    ->toggleable(false)
                    ->state(fn (Enrollment $record): ?string => $this->attendance()
                        ->recordAttendanceStatus($event, $record))
                    ->updateStateUsing(fn (Enrollment $record, mixed $state): ?string => $this->attendance()
                        ->setRecordAttendanceStatus($event, $record, $state)),
                IconColumn::make("attendance_notes_{$event->id}")
                    ->label('Notes')
                    ->toggleable(false)
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedDocumentText)
                    ->falseIcon(Heroicon::OutlinedDocumentText)
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->state(fn (Enrollment $record): bool => filled($this->attendance()
                        ->recordAttendanceNotes($event, $record)))
                    ->tooltip(fn (bool $state): string => match (true) {
                        Gate::denies('updateAttendance', $event) && $state => 'View notes',
                        Gate::denies('updateAttendance', $event) => 'No notes',
                        $state => 'View/edit notes',
                        default => 'Add notes',
                    })
                    ->action($this->notesAction($event)),
            ]))
            ->all();
    }

    private function notesAction(Event $event): Action
    {
        return Action::make("editAttendanceNotes_{$event->id}")
            ->label('Attendance Notes')
            ->icon(Heroicon::OutlinedDocumentText)
            ->authorize(fn (): bool => Gate::allows('view', $event))
            ->modalHeading(fn (Enrollment $record): string => 'Attendance Notes: '.$record->student->fullName)
            ->modalDescription('This note is private. Only owners, staff, and teachers can view it; parents and students cannot.')
            ->modalWidth('lg')
            ->modalSubmitAction(fn (Action $action): Action|false => Gate::allows('updateAttendance', $event) ? $action : false)
            ->modalCancelActionLabel(fn (): string => Gate::allows('updateAttendance', $event) ? 'Cancel' : 'Close')
            ->form([
                Textarea::make('notes')
                    ->label('Notes')
                    ->helperText('Private — not visible to parents or students.')
                    ->disabled(fn (): bool => Gate::denies('updateAttendance', $event))
                    ->rows(6),
            ])
            ->fillForm(fn (Model $record): array => [
                'notes' => $this->attendance()->recordAttendanceNotes($event, $record),
            ])
            ->action(function (array $data, Model $record) use ($event): void {
                $this->attendance()->setRecordAttendanceNotes($event, $record, $data['notes'] ?? null);
            });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Event>
     */
    private function courseEvents(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->course()
            ->events()
            ->orderByRaw('start_time is null')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->filter(fn (Event $event): bool => Gate::allows('view', $event));
    }

    private function course(): Course
    {
        $record = $this->getRecord();

        if (! $record instanceof Course) {
            throw new LogicException('Course attendance pages require a course record.');
        }

        return $record;
    }

    private function eventColumnLabel(Event $event): string
    {
        $date = filled($event->start_time)
            ? Carbon::parse($event->start_time)->timezone($this->displayTimezone())->format('m/d')
            : "Event {$event->id}";

        return filled($event->name) ? "{$date} {$event->name}" : $date;
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }

    private function attendance(): EventAttendanceService
    {
        return app(EventAttendanceService::class);
    }
}
