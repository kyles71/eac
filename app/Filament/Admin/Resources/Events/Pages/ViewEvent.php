<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Actions\CancelEventAction;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Tables\Columns\AttendanceRadioColumn;
use App\Models\Event;
use App\Services\EventAttendanceService;
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
            ->paginated(false);
    }

    protected function getHeaderActions(): array
    {
        return [
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
