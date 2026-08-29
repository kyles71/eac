<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Enrollments\AssignStudentToEnrollmentAction;
use App\Actions\Enrollments\UnassignStudentFromEnrollmentAction;
use App\Enums\CourseSemester;
use App\Filament\Shared\Actions\ViewCourseDetailsAction;
use App\Filament\User\Resources\Students\Schemas\StudentForm;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\EnrollmentStatus;
use App\Support\UserAttention;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Schemas\Schema as ComponentSchema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
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

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-user-my-enrollments-page'];
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
            CourseSemester::WinterSpring->value => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => Enrollment::applySemesterConstraint($query, CourseSemester::WinterSpring, $now)),
            CourseSemester::Summer->value => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => Enrollment::applySemesterConstraint($query, CourseSemester::Summer, $now)),
            CourseSemester::Fall->value => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => Enrollment::applySemesterConstraint($query, CourseSemester::Fall, $now)),
            'past' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => Enrollment::applyPastConstraint($query, $now)),
            'all' => Tab::make(),
        ];
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->query(
                Enrollment::query()
                    ->where('user_id', auth()->id())
                    ->with(['course.academicTerm', 'course.events', 'course.teachers.media', 'student'])
            )
            ->recordTitle(fn (Enrollment $record): string => $record->course->name)
            ->columns([
                TextColumn::make('course.name')
                    ->label('Course')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('academic_term')
                    ->label('Academic Term')
                    ->state(fn (Enrollment $record): ?string => $record->course?->academicTerm?->display_name)
                    ->badge()
                    ->color(fn (Enrollment $record): ?string => $record->course?->academicTerm?->semester->getColor()),
                TextColumn::make('student.fullName')
                    ->label('Student')
                    ->placeholder('Unassigned')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('course_starts_at')
                    ->label('Starts')
                    ->state(fn (Enrollment $record): mixed => $record->course?->firstMeetingStartsAt())
                    ->dateTime('M j, Y g:i A')
                    ->sortable(false),
                TextColumn::make('status')
                    ->state(fn (Enrollment $record): string => EnrollmentStatus::for($record))
                    ->badge()
                    ->color(fn (Enrollment $record): string => EnrollmentStatus::color($record)),
            ])
            ->filters([
                SelectFilter::make('student_id')
                    ->label('Student')
                    ->options(fn (): array => $this->studentOptions()),
            ])
            ->recordAction('viewCourseDetails')
            ->recordActions([
                ActionGroup::make([
                    ViewCourseDetailsAction::make(),
                    Action::make('assignStudent')
                        ->label(fn (Enrollment $record): string => $record->student_id === null ? 'Assign Student' : 'Change Student')
                        ->icon(Heroicon::OutlinedUser)
                        ->visible(fn (Enrollment $record): bool => ! $this->courseHasConcluded($record) && ($record->student_id === null || $this->canChangeAssignedStudent($record)))
                        ->stickyModalHeader(false)
                        ->stickyModalFooter(false)
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

                                $this->dispatch(UserAttention::UPDATED_EVENT);
                                $this->dispatch('refresh-sidebar');

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

                                $this->dispatch(UserAttention::UPDATED_EVENT);
                                $this->dispatch('refresh-sidebar');

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
                ]),
            ], RecordActionsPosition::BeforeCells)
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No classes')
            ->emptyStateDescription('Purchased classes and student assignments will appear here.')
            ->emptyStateIcon(Heroicon::OutlinedAcademicCap);
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

    private function courseHasConcluded(Enrollment $enrollment): bool
    {
        return $enrollment->course?->hasConcluded() ?? true;
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
