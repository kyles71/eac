<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Enrollments\AssignStudentToEnrollmentAction;
use App\Actions\Enrollments\UnassignStudentFromEnrollmentAction;
use App\Filament\User\Resources\Students\Schemas\StudentForm;
use App\Models\Enrollment;
use App\Models\Student;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Schemas\Schema as ComponentSchema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Attributes\Url;

final class MyEnrollments extends TablePage
{
    use HasTabs;

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $title = 'My Classes';

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent()
                    ->activeTab(1),
                EmbeddedTable::make(),
            ]);
    }

    public function getTabs(): array
    {
        $now = Carbon::now();

        return [
            'open' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'active' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->select('enrollments.*')->active($now)),
            'future' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->future($now)),
            'past' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->select('enrollments.*')->past($now)),
            'all' => Tab::make(),
        ];
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->query(
                Enrollment::query()
                    ->where('user_id', auth()->id())
                    ->with(['course.events', 'student'])
            )
            ->recordTitle(fn (Enrollment $record): string => $record->course?->name ?? 'Enrollment')
            ->columns([
                TextColumn::make('course.name')
                    ->label('Course')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('student.fullName')
                    ->label('Student')
                    ->placeholder('Unassigned')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('course.start_time')
                    ->label('Starts')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (Enrollment $record): string => $this->enrollmentStatus($record))
                    ->badge()
                    ->color(fn (Enrollment $record): string => match ($this->enrollmentStatus($record)) {
                        'Open' => 'warning',
                        'Active' => 'success',
                        'Future' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('student_id')
                    ->label('Student')
                    ->options(fn (): array => $this->studentOptions()),
            ])
            ->recordActions([
                Action::make('assignStudent')
                    ->label(fn (Enrollment $record): string => $record->student_id === null ? 'Assign Student' : 'Change Student')
                    ->icon(Heroicon::OutlinedUser)
                    ->visible(fn (Enrollment $record): bool => $record->student_id === null || $this->canChangeAssignedStudent($record))
                    ->schema([
                        Select::make('student_id')
                            ->label('Student')
                            ->options(fn (): array => $this->studentOptions())
                            ->default(fn (Enrollment $record): ?int => $record->student_id)
                            ->required()
                            ->searchable()
                            ->createOptionForm(fn (ComponentSchema $schema): ComponentSchema => StudentForm::configure($schema))
                            ->createOptionUsing(function (array $data): int {
                                /** @var \App\Models\User $user */
                                $user = auth()->user();

                                return $user->students()->create($data)->getKey();
                            }),
                    ])
                    ->action(function (Enrollment $record, array $data): void {
                        $student = Student::query()
                            ->where('user_id', auth()->id())
                            ->find($data['student_id']);

                        if ($student === null) {
                            Notification::make()
                                ->title('Student not found')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            /** @var \App\Models\User $user */
                            $user = auth()->user();

                            app(AssignStudentToEnrollmentAction::class)->handle($record, $student, $user);

                            Notification::make()
                                ->title('Enrollment updated')
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title('Could not update enrollment')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('removeStudent')
                    ->label('Remove Student')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (Enrollment $record): bool => $this->canUnassignStudent($record))
                    ->requiresConfirmation()
                    ->action(function (Enrollment $record): void {
                        try {
                            /** @var \App\Models\User $user */
                            $user = auth()->user();

                            app(UnassignStudentFromEnrollmentAction::class)->handle($record, $user);

                            Notification::make()
                                ->title('Student removed from enrollment')
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title('Could not remove student')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No classes')
            ->emptyStateDescription('Purchased classes and student assignments will appear here.')
            ->emptyStateIcon(Heroicon::OutlinedAcademicCap);
    }

    private function enrollmentStatus(Enrollment $enrollment): string
    {
        if ($enrollment->student_id === null) {
            return 'Open';
        }

        $course = $enrollment->course;

        if ($course === null) {
            return 'Past';
        }

        $now = now();

        if ($course->start_time?->gt($now)) {
            return 'Future';
        }

        if ($course->events->contains(fn ($event): bool => $event->start_time->gt($now))) {
            return 'Active';
        }

        return 'Past';
    }

    private function canChangeAssignedStudent(Enrollment $enrollment): bool
    {
        return app(AssignStudentToEnrollmentAction::class)->canChangeAssignedStudent($enrollment);
    }

    private function canUnassignStudent(Enrollment $enrollment): bool
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return app(UnassignStudentFromEnrollmentAction::class)->canHandle($enrollment, $user);
    }

    /**
     * @return array<int, string>
     */
    private function studentOptions(): array
    {
        return Student::query()
            ->where('user_id', auth()->id())
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (Student $student): array => [$student->id => $student->fullName])
            ->all();
    }
}
