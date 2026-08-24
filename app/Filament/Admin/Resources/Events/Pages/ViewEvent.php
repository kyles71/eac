<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Actions\CancelEventAction;
use App\Filament\Actions\SendEmailAction;
use App\Filament\Actions\StudentContactActionGroup;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Tables\Columns\AttendanceRadioColumn;
use App\Models\Event;
use App\Models\Student;
use App\Services\EventAttendanceService;
use App\Services\EventEmailRecipientsService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class ViewEvent extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = EventResource::class;

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
            ->query($this->attendanceQuery())
            ->heading($this->attendanceHeading())
            ->description('Attendance notes are private. Only owners, staff, and teachers can view them; parents and students cannot.')
            ->columns([
                TextColumn::make('attendance_student_name')
                    ->label('Student')
                    ->state(fn (Model $record): string => $this->attendance()->recordStudentName($record)),
                AttendanceRadioColumn::make('attendance_status')
                    ->label('Attendance')
                    ->disabled(fn (): bool => Gate::denies('updateAttendance', $this->event()))
                    ->state(fn (Model $record): ?string => $this->attendance()
                        ->recordStudentAttendanceStatus($this->event(), $record))
                    ->updateStateUsing(fn (Model $record, mixed $state): ?string => $this->attendance()
                        ->setRecordStudentAttendanceStatus($this->event(), $record, $state)),
                TextColumn::make('notes')
                    ->label('Notes')
                    ->state(fn (Model $record): ?string => $this->attendance()->recordStudentNotes($this->event(), $record))
                    ->placeholder('No notes')
                    ->wrap()
                    ->limit(60),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->attendanceNotesAction(),
                ])
                    ->label('Attendance Notes')
                    ->icon(Heroicon::OutlinedDocumentText),
                StudentContactActionGroup::make(
                    student: fn (Model $record): Student => $this->attendanceStudent($record),
                    event: fn (): Event => $this->event(),
                )
                    ->visible(fn (Model $record): bool => $this->canEmailAttendanceRecord($record)),
            ], RecordActionsPosition::BeforeCells)
            ->headerActions([
                SendEmailAction::make('emailAttendance')
                    ->label(fn (): string => $this->event()->course_id === null ? 'Email Attendees' : 'Email Class')
                    ->to(fn (): array => app(EventEmailRecipientsService::class)->forEvent($this->event())),
            ])
            ->paginated(false);
    }

    protected function getHeaderActions(): array
    {
        return [
            SendEmailAction::make()
                ->label(fn (): string => $this->event()->course_id === null ? 'Email Attendees' : 'Email Class')
                ->to(fn (): array => app(EventEmailRecipientsService::class)->forEvent($this->event())),
            CancelEventAction::make(),
            EditAction::make()
                ->visible(fn (): bool => ! $this->event()->isCancelled()),
        ];
    }

    /**
     * @return Builder<Model>
     */
    private function attendanceQuery(): Builder
    {
        return $this->attendance()->eventRosterQuery($this->event());
    }

    private function attendance(): EventAttendanceService
    {
        return app(EventAttendanceService::class);
    }

    private function attendanceStudent(Model $record): Student
    {
        $student = $this->attendance()->studentForAttendanceRecord($record);

        if (! $student instanceof Student) {
            throw new LogicException('The attendance student is unavailable.');
        }

        return $student;
    }

    private function attendanceNotesAction(): Action
    {
        return Action::make('editAttendanceNotes')
            ->label('Attendance Notes')
            ->icon(Heroicon::OutlinedDocumentText)
            ->authorize(fn (Model $record): bool => Gate::allows('view', $this->event())
                && $this->attendance()->studentForAttendanceRecord($record) instanceof Student)
            ->modalHeading(fn (Model $record): string => 'Attendance Notes: '.$this->attendanceStudent($record)->fullName)
            ->modalDescription('This note is private. Only owners, staff, and teachers can view it; parents and students cannot.')
            ->modalWidth('lg')
            ->modalSubmitAction(fn (Action $action): Action|false => Gate::allows('updateAttendance', $this->event()) ? $action : false)
            ->modalCancelActionLabel(fn (): string => Gate::allows('updateAttendance', $this->event()) ? 'Cancel' : 'Close')
            ->form([
                Textarea::make('notes')
                    ->label('Notes')
                    ->helperText('Private — not visible to parents or students.')
                    ->disabled(fn (): bool => Gate::denies('updateAttendance', $this->event()))
                    ->rows(6),
            ])
            ->fillForm(fn (Model $record): array => [
                'notes' => $this->attendance()->recordStudentNotes($this->event(), $record),
            ])
            ->action(function (array $data, Model $record): void {
                $this->attendance()->setRecordStudentNotes($this->event(), $record, $data['notes'] ?? null);

                Notification::make()
                    ->title('Attendance note saved')
                    ->success()
                    ->send();
            });
    }

    private function canEmailAttendanceRecord(Model $record): bool
    {
        $student = $this->attendance()->studentForAttendanceRecord($record);

        return $student instanceof Student && Gate::allows('view', $student);
    }

    private function attendanceHeading(): string
    {
        $startTime = $this->event()->start_time;

        if ($startTime === null) {
            return 'Attendance — Date not set';
        }

        return 'Attendance — '.Carbon::parse($startTime)
            ->timezone($this->displayTimezone())
            ->format('l, F j, Y');
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }

    private function event(): Event
    {
        $record = $this->getRecord();

        if (! $record instanceof Event) {
            throw new LogicException('The event record is unavailable.');
        }

        return $record;
    }
}
