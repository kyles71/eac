<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Actions\CancelEventAction;
use App\Filament\Actions\SendEmailAction;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Tables\Columns\AttendanceRadioColumn;
use App\Models\Event;
use App\Models\Student;
use App\Services\EventAttendanceService;
use App\Services\EventEmailRecipientsService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
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
                TextInputColumn::make('notes')
                    ->label('Notes')
                    ->disabled(fn (): bool => Gate::denies('updateAttendance', $this->event()))
                    ->state(fn (Model $record): ?string => $this->attendance()->recordStudentNotes($this->event(), $record))
                    ->updateStateUsing(fn (Model $record, mixed $state): ?string => $this->attendance()
                        ->setRecordStudentNotes($this->event(), $record, $state)),
            ])
            ->recordActions([
                SendEmailAction::make()
                    ->label('Email Student')
                    ->to(fn (Model $record): array => $this->emailRecipientForAttendanceRecord($record))
                    ->visible(fn (Model $record): bool => $this->canEmailAttendanceRecord($record)),
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

    /**
     * @return array<int, Student>
     */
    private function emailRecipientForAttendanceRecord(Model $record): array
    {
        $student = $this->attendance()->studentForAttendanceRecord($record);

        return $student instanceof Student ? [$student] : [];
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
