<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Pages;

use App\Filament\Admin\Resources\Courses\CourseResource;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
        return $this->attendance()->courseRosterQuery($this->getRecord()->id);
    }

    /**
     * @return array<int, ColumnGroup>
     */
    private function eventAttendanceColumns(): array
    {
        return $this->courseEvents()
            ->map(fn (Event $event): ColumnGroup => ColumnGroup::make($this->eventColumnLabel($event), [
                ToggleColumn::make("attendance_{$event->id}")
                    ->label('Present')
                    ->toggleable(false)
                    ->state(fn (Enrollment $record): bool => $this->attendance()->recordStudentAttended($event, $record))
                    ->updateStateUsing(fn (Enrollment $record, mixed $state): bool => $this->attendance()
                        ->setRecordStudentAttendance($event, $record, $state)),
                IconColumn::make("attendance_notes_{$event->id}")
                    ->label('Notes')
                    ->toggleable(false)
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedDocumentText)
                    ->falseIcon(Heroicon::OutlinedDocumentText)
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->state(fn (Enrollment $record): bool => filled($this->attendance()
                        ->recordStudentNotes($event, $record)))
                    ->tooltip(fn (bool $state): string => $state ? 'View/edit notes' : 'Add notes')
                    ->action($this->notesAction($event)),
            ]))
            ->all();
    }

    private function notesAction(Event $event): Action
    {
        return Action::make("editAttendanceNotes_{$event->id}")
            ->label('Attendance Notes')
            ->icon(Heroicon::OutlinedDocumentText)
            ->modalHeading(fn (Enrollment $record): string => 'Attendance Notes: '.$record->student->fullName)
            ->modalWidth('lg')
            ->form([
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(6),
            ])
            ->fillForm(fn (Model $record): array => [
                'notes' => $this->attendance()->recordStudentNotes($event, $record),
            ])
            ->action(function (array $data, Model $record) use ($event): void {
                $this->attendance()->setRecordStudentNotes($event, $record, $data['notes'] ?? null);
            });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Event>
     */
    private function courseEvents(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->getRecord()
            ->events()
            ->orderByRaw('start_time is null')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
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
