<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\Reports\ReportDataset;
use App\Enums\AttendanceStatus;
use App\Enums\EventSubstituteCoverageStatus;
use App\Enums\ReportKey;
use App\Models\AcademicTerm;
use App\Models\CompetitionSeason;
use App\Models\Course;
use App\Models\EmergencyContact;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventSubstituteCoverage;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Services\StudentProfileService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class InstructorReportService
{
    public function __construct(private StudentProfileService $studentProfiles) {}

    /**
     * @return array{
     *     instructor_count: int,
     *     scheduled_event_count: int,
     *     scheduled_hours: float,
     *     completed_hours: float,
     *     substitute_event_count: int,
     *     substitute_hours: float,
     *     needs_coverage_count: int,
     *     cancelled_event_count: int,
     *     overall_attendance_rate: float|null,
     *     overall_sub_rate: float|null
     * }
     */
    public function dashboard(?AcademicTerm $term, User $user): array
    {
        if (! $term instanceof AcademicTerm) {
            return $this->emptyDashboard();
        }

        $filters = [
            'academic_term_id' => ['value' => $term->id],
            'date_range' => [
                'from' => $term->starts_on->toDateString(),
                'through' => $term->ends_on->toDateString(),
            ],
        ];
        $teachingRows = $this->teachingRows($user, $filters);
        $substituteRows = collect($teachingRows)->where('role', 'Substitute');
        [$startsAt, $endsAt] = $this->dateRange($filters);
        $cancelledEvents = $this->applyEventAccessConstraint(
            Event::query()
                ->whereNotNull('cancelled_at')
                ->whereBetween('start_time', [$startsAt, $endsAt])
                ->where(function (Builder $query) use ($term): void {
                    $query
                        ->whereNull('course_id')
                        ->orWhereHas('course', fn (Builder $query): Builder => $query
                            ->where('academic_term_id', $term->id));
                }),
            $user,
        )->count();
        $needsCoverage = $this->applyEventAccessConstraint(
            Event::query()
                ->whereNull('cancelled_at')
                ->whereHas('activeSubstituteCoverages', fn (Builder $query): Builder => $query
                    ->whereNull('substitute_teacher_id'))
                ->whereBetween('start_time', [$startsAt, $endsAt])
                ->where(function (Builder $query) use ($term): void {
                    $query
                        ->whereNull('course_id')
                        ->orWhereHas('course', fn (Builder $query): Builder => $query
                            ->where('academic_term_id', $term->id));
                }),
            $user,
        )->count();
        $occurredEvents = $this->applyEventAccessConstraint(
            Event::query()
                ->whereNull('cancelled_at')
                ->whereBetween('start_time', [$startsAt, $endsAt])
                ->where('start_time', '<=', now())
                ->where(function (Builder $query) use ($term): void {
                    $query
                        ->whereNull('course_id')
                        ->orWhereHas('course', fn (Builder $query): Builder => $query
                            ->where('academic_term_id', $term->id));
                })
                ->with(['course.enrollments', 'attendees', 'substituteCoverages']),
            $user,
        )->get();
        $attendanceOpportunities = 0;
        $attended = 0;

        foreach ($occurredEvents as $event) {
            if (! $event->course instanceof Course) {
                continue;
            }

            $studentIds = $event->course->enrollments
                ->pluck('student_id')
                ->filter()
                ->unique();
            $attendanceOpportunities += $studentIds->count();
            $attended += $event->attendees
                ->where('attendee_type', (new Student)->getMorphClass())
                ->whereIn('attendee_id', $studentIds)
                ->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])
                ->count();
        }

        return [
            'instructor_count' => collect($teachingRows)
                ->reject(fn (array $row): bool => $row['instructor_key'] === 'unassigned')
                ->pluck('instructor_key')
                ->unique()
                ->count(),
            'scheduled_event_count' => collect($teachingRows)->pluck('event_id')->unique()->count(),
            'scheduled_hours' => round((float) collect($teachingRows)->sum('hours'), 2),
            'completed_hours' => round((float) collect($teachingRows)
                ->where('status', 'Completed')
                ->sum('hours'), 2),
            'substitute_event_count' => $substituteRows->pluck('event_id')->unique()->count(),
            'substitute_hours' => round((float) $substituteRows->sum('hours'), 2),
            'needs_coverage_count' => $needsCoverage,
            'cancelled_event_count' => $cancelledEvents,
            'overall_attendance_rate' => $this->percentageValue($attended, $attendanceOpportunities),
            'overall_sub_rate' => $this->percentageValue(
                $occurredEvents->filter(fn (Event $event): bool => $event->substituteCoverages->isNotEmpty())->count(),
                $occurredEvents->count(),
            ),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function dataset(ReportKey $report, User $user, array $filters): ReportDataset
    {
        return match ($report) {
            ReportKey::InstructorClassAssignments => $this->classAssignments($user, $filters),
            ReportKey::InstructorTeachingSchedule => $this->teachingSchedule($user, $filters),
            ReportKey::InstructorHoursSummary => $this->hoursSummary($user, $filters),
            ReportKey::SubstituteCoverage => $this->substituteCoverage($user, $filters),
            ReportKey::ClassRosters => $this->classRosters($user, $filters),
            ReportKey::InstructorSchedule => $this->instructorSchedule($user, $filters),
            ReportKey::ClassSafetyRoster => $this->classSafetyRoster($user, $filters),
            ReportKey::EmergencyTextsByCourse => $this->emergencyTextsByCourse($user, $filters),
            ReportKey::ClassAttendance => $this->classAttendance($user, $filters),
            ReportKey::CompetitionAttendance => $this->competitionAttendance($user, $filters),
            ReportKey::OverallAttendance => $this->overallAttendance($user, $filters),
            ReportKey::InstructorSubReport => $this->instructorSubReport($user, $filters),
            default => throw new InvalidArgumentException("{$report->label()} is not an instructor report."),
        };
    }

    public function currentTerm(): ?AcademicTerm
    {
        return AcademicTerm::query()->current()->orderByDesc('starts_on')->first();
    }

    public function currentCompetitionSeason(): ?CompetitionSeason
    {
        return CompetitionSeason::query()->current()->orderByDesc('starts_on')->first();
    }

    public function defaultCourseId(User $user): ?int
    {
        return $this->courseQuery($user, $this->currentTerm()?->id)
            ->orderBy('name')
            ->orderBy('id')
            ->value('id');
    }

    /** @return array<string, array<int, string>> */
    public function academicTermOptions(): array
    {
        return AcademicTerm::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->get()
            ->groupBy(fn (AcademicTerm $term): string => $term->academicYear->display_name)
            ->map(fn (Collection $terms): array => $terms
                ->mapWithKeys(fn (AcademicTerm $term): array => [$term->id => $term->display_name])
                ->all())
            ->all();
    }

    /** @return array<int, string> */
    public function instructorOptions(User $user): array
    {
        if ($user->hasCourseRestrictedAdminAccess()) {
            return [$user->id => $user->fullName];
        }

        return User::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('roles', fn (Builder $query): Builder => $query
                        ->where('name', Role::TEACHER))
                    ->orWhereHas('teachingCourses')
                    ->orWhereHas('substituteEvents');
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $instructor): array => [$instructor->id => $instructor->fullName])
            ->all();
    }

    /** @return array<string, string> */
    public function substituteCoverageStatusOptions(): array
    {
        return collect(EventSubstituteCoverageStatus::cases())
            ->reject(fn (EventSubstituteCoverageStatus $status): bool => $status === EventSubstituteCoverageStatus::NotNeeded)
            ->mapWithKeys(fn (EventSubstituteCoverageStatus $status): array => [
                $status->value => $status->getLabel(),
            ])
            ->all();
    }

    public function defaultDateFrom(): string
    {
        return $this->currentTerm()?->starts_on->toDateString()
            ?? now($this->displayTimezone())->startOfMonth()->toDateString();
    }

    public function defaultDateThrough(): string
    {
        return $this->currentTerm()?->ends_on->toDateString()
            ?? now($this->displayTimezone())->endOfMonth()->toDateString();
    }

    /** @return array<string, array<int, string>> */
    public function courseOptions(User $user): array
    {
        return $this->courseQuery($user)
            ->with('academicTerm')
            ->orderByDesc('academic_term_id')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Course $course): string => $course->academicTerm->display_name ?? 'No Academic Term')
            ->map(fn (Collection $courses): array => $courses
                ->mapWithKeys(fn (Course $course): array => [$course->id => $course->name])
                ->all())
            ->all();
    }

    /** @return array<int, string> */
    public function competitionSeasonOptions(): array
    {
        return CompetitionSeason::query()
            ->orderByDesc('starts_on')
            ->pluck('name', 'id')
            ->all();
    }

    /** @param array<string, mixed> $filters */
    private function classAssignments(User $user, array $filters): ReportDataset
    {
        $headers = [
            'instructor_name' => 'Instructor',
            'course_name' => 'Class',
            'academic_term' => 'Academic Term',
            'role' => 'Assignment',
            'enrollment_count' => 'Enrollments',
            'event_count' => 'Scheduled Events',
            'first_meeting' => 'First Meeting',
            'last_meeting' => 'Last Meeting',
        ];
        $termId = $this->integerFilterValue($filters, 'academic_term_id');
        $instructorId = $this->instructorFilterId($filters, $user);
        $query = Course::query()
            ->when($termId !== null, fn (Builder $query): Builder => $query
                ->where('academic_term_id', $termId))
            ->when($user->hasCourseRestrictedAdminAccess(), fn (Builder $query): Builder => $query
                ->whereHas('teachers', fn (Builder $query): Builder => $query->whereKey($user->id)))
            ->with([
                'academicTerm',
                'teachers:id,first_name,last_name',
                'events' => fn ($query) => $query
                    ->whereNull('cancelled_at')
                    ->whereNotNull('start_time')
                    ->orderBy('start_time'),
            ])
            ->withCount('enrollments')
            ->orderBy('name')
            ->orderBy('id');
        $rows = [];

        foreach ($query->get() as $course) {
            foreach ($this->courseAssignmentInstructors($course, $user) as $instructor) {
                if ($instructorId !== null && $instructor['user_id'] !== $instructorId) {
                    continue;
                }

                $firstEvent = $course->events->first();
                $lastEvent = $course->events->last();
                $rows[] = [
                    '_key' => "course_{$course->id}_{$instructor['key']}",
                    'instructor_name' => $instructor['name'],
                    'course_name' => $course->name,
                    'academic_term' => $course->academicTerm->display_name ?? 'Unassigned',
                    'role' => $instructor['role'],
                    'enrollment_count' => (int) $course->enrollments_count,
                    'event_count' => $course->events->count(),
                    'first_meeting' => $this->formatDateTime($firstEvent->start_time ?? null),
                    'last_meeting' => $this->formatDateTime($lastEvent->end_time ?? $lastEvent->start_time ?? null),
                ];
            }
        }

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function teachingSchedule(User $user, array $filters): ReportDataset
    {
        $headers = [
            'date' => 'Date',
            'start_time' => 'Starts',
            'end_time' => 'Ends',
            'instructor_name' => 'Instructor',
            'course_name' => 'Class',
            'enrollment_count' => 'Number of Enrollments',
            'academic_term' => 'Academic Term',
            'role' => 'Teaching Role',
            'hours' => 'Hours',
            'status' => 'Event Status',
        ];
        $rows = collect($this->teachingRows($user, $filters))
            ->map(fn (array $row): array => [
                '_key' => "event_{$row['event_id']}_{$row['instructor_key']}",
                'date' => $row['starts_at']->format('Y-m-d'),
                'start_time' => $row['starts_at']->format('g:i A'),
                'end_time' => $row['ends_at']->format('g:i A'),
                'instructor_name' => $row['instructor_name'],
                'course_name' => $row['course_name'],
                'enrollment_count' => $row['enrollment_count'],
                'academic_term' => $row['academic_term'],
                'role' => $row['role'],
                'hours' => $row['hours'],
                'status' => $row['status'],
            ])
            ->all();

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function hoursSummary(User $user, array $filters): ReportDataset
    {
        $headers = [
            'instructor_name' => 'Instructor',
            'event_count' => 'Events',
            'scheduled_hours' => 'Scheduled Hours',
            'completed_hours' => 'Completed Hours',
            'upcoming_hours' => 'Upcoming Hours',
            'sub_hours_needed' => 'Sub Hours Needed',
            'sub_hours_covered' => 'Sub Hours Covered',
        ];
        $teachingRows = collect($this->teachingRows($user, $filters));
        $teachingRowsByInstructor = $teachingRows->groupBy('instructor_key');
        $subHoursNeededByInstructor = $this->subHoursNeededByInstructor($user, $filters);
        $rows = $teachingRowsByInstructor
            ->keys()
            ->merge($subHoursNeededByInstructor->keys())
            ->unique()
            ->map(function (string $key) use ($subHoursNeededByInstructor, $teachingRowsByInstructor): array {
                $instructorRows = $teachingRowsByInstructor->get($key, collect());
                $substituteRows = $instructorRows->where('role', 'Substitute');
                $subHoursNeeded = $subHoursNeededByInstructor->get($key);

                return [
                    '_key' => "instructor_{$key}",
                    'instructor_name' => (string) ($instructorRows->first()['instructor_name'] ?? $subHoursNeeded['instructor_name']),
                    'event_count' => $instructorRows->pluck('event_id')->unique()->count(),
                    'scheduled_hours' => round((float) $instructorRows->sum('hours'), 2),
                    'completed_hours' => round((float) $instructorRows->where('status', 'Completed')->sum('hours'), 2),
                    'upcoming_hours' => round((float) $instructorRows->where('status', 'Upcoming')->sum('hours'), 2),
                    'sub_hours_needed' => round((float) ($subHoursNeeded['hours'] ?? 0), 2),
                    'sub_hours_covered' => round((float) $substituteRows->sum('hours'), 2),
                ];
            })
            ->values()
            ->all();
        $footer = [
            '_key' => 'footer_total',
            'instructor_name' => 'Total',
            'event_count' => collect($rows)->sum('event_count'),
            'scheduled_hours' => round((float) collect($rows)->sum('scheduled_hours'), 2),
            'completed_hours' => round((float) collect($rows)->sum('completed_hours'), 2),
            'upcoming_hours' => round((float) collect($rows)->sum('upcoming_hours'), 2),
            'sub_hours_needed' => round((float) collect($rows)->sum('sub_hours_needed'), 2),
            'sub_hours_covered' => round((float) collect($rows)->sum('sub_hours_covered'), 2),
        ];

        return new ReportDataset($headers, $rows, [$footer]);
    }

    /** @param array<string, mixed> $filters */
    private function substituteCoverage(User $user, array $filters): ReportDataset
    {
        $headers = [
            'date' => 'Date',
            'time' => 'Time',
            'course_name' => 'Class',
            'academic_term' => 'Academic Term',
            'assigned_instructors' => 'Assigned Instructor(s)',
            'reason' => 'Reason',
            'confirmed_substitute' => 'Confirmed Substitute',
            'coverage_status' => 'Coverage Status',
            'hours' => 'Hours',
        ];
        $instructorId = $this->instructorFilterId($filters, $user);
        $coverageStatus = $this->filterValue($filters, 'coverage_status');
        $events = $this->eventQuery($user, $filters)
            ->whereHas('substituteCoverages')
            ->with(['substituteCoverages.coveredTeacher', 'substituteCoverages.substituteTeacher', 'substituteCoverages.requests'])
            ->get();
        $rows = [];

        foreach ($events as $event) {
            foreach ($event->substituteCoverages as $coverage) {
                $status = $this->coverageSlotStatus($coverage);

                if (filled($coverageStatus) && $status['value'] !== $coverageStatus) {
                    continue;
                }

                if ($instructorId !== null
                    && $coverage->covered_teacher_id !== $instructorId
                    && $coverage->substitute_teacher_id !== $instructorId) {
                    continue;
                }

                $startsAt = $event->start_time->copy()->timezone($this->displayTimezone());
                $endsAt = $event->end_time->copy()->timezone($this->displayTimezone());
                $rows[] = [
                    '_key' => "coverage_{$coverage->id}",
                    'date' => $startsAt->format('Y-m-d'),
                    'time' => $startsAt->format('g:i A').'–'.$endsAt->format('g:i A'),
                    'course_name' => $this->eventCourseName($event),
                    'academic_term' => $this->eventAcademicTermName($event),
                    'assigned_instructors' => $coverage->coveredTeacherName()
                        .' (of '.$this->assignedEventInstructorNames($event).')',
                    'reason' => $this->substituteCoverageReason($coverage),
                    'confirmed_substitute' => $coverage->substituteTeacherName() ?? '—',
                    'coverage_status' => $status['label'],
                    'hours' => $this->durationHours($event),
                ];
            }
        }

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function classRosters(User $user, array $filters): ReportDataset
    {
        $headers = [
            'dancer_name' => 'Dancer Name',
            'media_release' => 'Media Release',
        ];
        $course = $this->selectedCourse($user, $filters);

        if (! $course instanceof Course) {
            return new ReportDataset($headers, []);
        }

        $rows = Enrollment::query()
            ->where('course_id', $course->id)
            ->whereNotNull('student_id')
            ->with('student')
            ->get()
            ->filter(fn (Enrollment $enrollment): bool => $enrollment->student instanceof Student)
            ->map(fn (Enrollment $enrollment): array => [
                '_key' => "enrollment_{$enrollment->id}",
                'dancer_name' => $enrollment->student->fullName,
                'media_release' => $this->mediaReleaseStatus($enrollment->student),
            ])
            ->sortBy('dancer_name')
            ->values()
            ->all();

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function instructorSchedule(User $user, array $filters): ReportDataset
    {
        $headers = [
            'instructor_name' => 'Instructor',
            'course_name' => 'Course Name',
            'day_of_week' => 'Day of Week',
            'start_time' => 'Start Time',
            'end_time' => 'End Time',
            'enrollment_count' => 'Enrollments',
            'additional_instructors' => 'Additional Instructor Names',
        ];
        $termId = $this->academicTermId($filters);
        $instructorId = $this->instructorFilterId($filters, $user);
        $courses = $this->courseQuery($user, $termId)
            ->with([
                'teachers:id,first_name,last_name',
                'events' => fn ($query) => $query
                    ->whereNull('cancelled_at')
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->orderBy('start_time'),
            ])
            ->withCount('enrollments')
            ->orderBy('name')
            ->get();
        $rows = [];

        foreach ($courses as $course) {
            foreach ($this->courseAssignmentInstructors($course, $user) as $instructor) {
                if ($instructorId !== null && $instructor['user_id'] !== $instructorId) {
                    continue;
                }

                $schedule = $course->events
                    ->map(function (Event $event): array {
                        $startsAt = $event->start_time->copy()->timezone($this->displayTimezone());
                        $endsAt = $event->end_time->copy()->timezone($this->displayTimezone());

                        return [
                            'day' => $startsAt->format('l'),
                            'starts' => $startsAt->format('g:i A'),
                            'ends' => $endsAt->format('g:i A'),
                        ];
                    })
                    ->unique(fn (array $event): string => implode('|', $event));
                $rows[] = [
                    '_key' => "course_{$course->id}_{$instructor['key']}",
                    'instructor_name' => $instructor['name'],
                    'course_name' => $course->name,
                    'day_of_week' => $schedule->pluck('day')->unique()->join(', '),
                    'start_time' => $schedule->pluck('starts')->unique()->join(', '),
                    'end_time' => $schedule->pluck('ends')->unique()->join(', '),
                    'enrollment_count' => (int) $course->enrollments_count,
                    'additional_instructors' => $this->additionalInstructorNames(
                        $course,
                        $instructor['user_id'],
                        $instructor['name'],
                    ),
                ];
            }
        }

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function classSafetyRoster(User $user, array $filters): ReportDataset
    {
        $course = $this->selectedCourse($user, $filters);
        $enrollments = $course instanceof Course
            ? Enrollment::query()
                ->where('course_id', $course->id)
                ->whereNotNull('student_id')
                ->with(['student', 'user'])
                ->get()
            : collect();
        $roster = $enrollments
            ->filter(fn (Enrollment $enrollment): bool => $enrollment->student instanceof Student)
            ->map(function (Enrollment $enrollment): array {
                $waiver = $this->studentProfiles->medicalWaiver($enrollment->student);
                $waiver?->loadMissing('emergencyContacts');

                return compact('enrollment', 'waiver');
            });
        $maximumContacts = (int) $roster->max(
            fn (array $entry): int => $entry['waiver']?->emergencyContacts->count() ?? 0,
        );
        $headers = [
            'dancer_name' => 'Dancer Name',
            'user_name' => 'User Name',
            ...$this->emergencyContactHeaders($maximumContacts),
            'allergies' => 'Allergies',
            'medical_conditions' => 'Medical Conditions',
            'medications' => 'Allowed Medications',
            'behavioral_notes' => 'Behavioral Notes',
        ];
        $rows = $roster->map(function (array $entry) use ($maximumContacts): array {
            /** @var Enrollment $enrollment */
            $enrollment = $entry['enrollment'];
            /** @var StudentWaiver|null $waiver */
            $waiver = $entry['waiver'];
            $row = [
                '_key' => "enrollment_{$enrollment->id}",
                'dancer_name' => $enrollment->student->fullName,
                'user_name' => $enrollment->user->fullName,
            ];

            for ($number = 1; $number <= $maximumContacts; $number++) {
                $contact = $waiver?->emergencyContacts->values()->get($number - 1);
                $row["emergency_contact_{$number}_name"] = $contact->name ?? '';
                $row["emergency_contact_{$number}_phone"] = $contact->phone_number ?? '';
                $row["emergency_contact_{$number}_email"] = $contact->email ?? '';
            }

            return [
                ...$row,
                'allergies' => $waiver->allergies ?? '',
                'medical_conditions' => $waiver->medical_conditions ?? '',
                'medications' => $waiver->medications ?? '',
                'behavioral_notes' => $waiver->behavioral_notes ?? '',
            ];
        })->sortBy('dancer_name')->values()->all();

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function emergencyTextsByCourse(User $user, array $filters): ReportDataset
    {
        $headers = [
            'dancer_name' => 'Dancer Name',
            'emergency_contact_name' => 'Emergency Contact Name',
            'phone_number' => 'Phone Number',
        ];
        $termId = $this->integerFilterValue($filters, 'academic_term_id');
        $courseId = $this->integerFilterValue($filters, 'course_id');
        $courseIds = $this->courseQuery($user, $termId)
            ->when($courseId !== null, fn (Builder $query): Builder => $query->whereKey($courseId))
            ->pluck('id');
        $roster = Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('student_id')
            ->with('student')
            ->get()
            ->unique('student_id')
            ->filter(fn (Enrollment $enrollment): bool => $enrollment->student instanceof Student)
            ->map(function (Enrollment $enrollment): array {
                $waiver = $this->studentProfiles->medicalWaiver($enrollment->student);
                $waiver?->loadMissing('emergencyContacts');

                return [
                    'enrollment' => $enrollment,
                    'contacts' => $waiver?->emergencyContacts
                        ->where('wants_text_updates', true)
                        ->values() ?? collect(),
                ];
            });
        $rows = $roster->flatMap(function (array $entry): array {
            /** @var Enrollment $enrollment */
            $enrollment = $entry['enrollment'];
            $contacts = $entry['contacts'];

            if ($contacts->isEmpty()) {
                return [[
                    '_key' => "enrollment_{$enrollment->id}_contact_none",
                    'dancer_name' => $enrollment->student->fullName,
                    'emergency_contact_name' => '',
                    'phone_number' => '',
                ]];
            }

            return $contacts
                ->map(fn (EmergencyContact $contact): array => [
                    '_key' => "enrollment_{$enrollment->id}_contact_{$contact->id}",
                    'dancer_name' => $enrollment->student->fullName,
                    'emergency_contact_name' => $contact->name,
                    'phone_number' => $contact->phone_number,
                ])
                ->all();
        })->sortBy(fn (array $row): string => mb_strtolower("{$row['dancer_name']} {$row['emergency_contact_name']}"))
            ->values()
            ->all();

        return new ReportDataset($headers, $rows);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<string, array{instructor_name: string, hours: float}>
     */
    private function subHoursNeededByInstructor(User $user, array $filters): Collection
    {
        $instructorId = $this->instructorFilterId($filters, $user);
        $hoursByInstructor = collect();
        $events = $this->eventQuery($user, $filters)
            ->whereHas('substituteCoverages')
            ->with('substituteCoverages.coveredTeacher')
            ->get();

        foreach ($events as $event) {
            foreach ($event->substituteCoverages as $coverage) {
                $coveredTeacher = $coverage->coveredTeacher;

                if (! $coveredTeacher instanceof User
                    || ($instructorId !== null && $coveredTeacher->id !== $instructorId)
                    || ($user->hasCourseRestrictedAdminAccess() && $coveredTeacher->id !== $user->id)) {
                    continue;
                }

                $key = "user_{$coveredTeacher->id}";
                $current = $hoursByInstructor->get($key, [
                    'instructor_name' => $coveredTeacher->fullName,
                    'hours' => 0.0,
                ]);
                $current['hours'] += $this->durationHours($event);
                $hoursByInstructor->put($key, $current);
            }
        }

        return $hoursByInstructor;
    }

    /** @param array<string, mixed> $filters */
    private function classAttendance(User $user, array $filters): ReportDataset
    {
        $headers = [
            'dancer_name' => 'Dancer Name',
            'attended' => 'Attended',
            'late' => 'Late',
            'excused_absence' => 'Excused Absence',
            'unexcused_absence' => 'Unexcused Absence',
        ];
        $course = $this->selectedCourse($user, $filters);

        if (! $course instanceof Course) {
            return new ReportDataset($headers, []);
        }

        [$startsAt, $endsAt] = $this->dateRange($filters);
        $eventIds = Event::query()
            ->where('course_id', $course->id)
            ->whereNull('cancelled_at')
            ->whereBetween('start_time', [$startsAt, $endsAt])
            ->pluck('id');
        $attendees = EventAttendee::query()
            ->whereIn('event_id', $eventIds)
            ->where('attendee_type', (new Student)->getMorphClass())
            ->get()
            ->groupBy('attendee_id');
        $rows = Enrollment::query()
            ->where('course_id', $course->id)
            ->whereNotNull('student_id')
            ->with('student')
            ->get()
            ->filter(fn (Enrollment $enrollment): bool => $enrollment->student instanceof Student)
            ->map(function (Enrollment $enrollment) use ($attendees): array {
                $counts = $this->attendanceCounts($attendees->get($enrollment->student_id, collect()));

                return [
                    '_key' => "enrollment_{$enrollment->id}",
                    'dancer_name' => $enrollment->student->fullName,
                    ...$counts,
                ];
            })
            ->sortBy('dancer_name')
            ->values()
            ->all();

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function competitionAttendance(User $user, array $filters): ReportDataset
    {
        $headers = [
            'dancer_name' => 'Dancer Name',
            'course_name' => 'Course',
            'attendance_rate' => 'Attendance Rate',
            'excused_absences' => 'Excused Absences',
            'unexcused_absences' => 'Unexcused Absences',
        ];
        $seasonId = $this->competitionSeasonId($filters);

        if ($seasonId === null) {
            return new ReportDataset($headers, []);
        }

        $studentIds = Student::query()
            ->whereHas('competitionTeams', fn (Builder $query): Builder => $query
                ->where('competition_season_id', $seasonId))
            ->pluck('id');
        $courses = $this->courseQuery($user, $this->academicTermId($filters))
            ->whereHas('enrollments', fn (Builder $query): Builder => $query
                ->whereIn('student_id', $studentIds))
            ->with([
                'enrollments' => fn ($query) => $query
                    ->whereIn('student_id', $studentIds)
                    ->whereNotNull('student_id')
                    ->with('student'),
                'events' => fn ($query) => $query
                    ->whereNull('cancelled_at')
                    ->whereNotNull('start_time')
                    ->where('start_time', '<=', now())
                    ->with('attendees'),
            ])
            ->orderBy('name')
            ->get();
        $rows = [];

        foreach ($courses as $course) {
            foreach ($course->enrollments as $enrollment) {
                if (! $enrollment->student instanceof Student) {
                    continue;
                }

                $attendees = $course->events
                    ->flatMap->attendees
                    ->where('attendee_type', $enrollment->student->getMorphClass())
                    ->where('attendee_id', $enrollment->student_id);
                $counts = $this->attendanceCounts($attendees);
                $rows[] = [
                    '_key' => "course_{$course->id}_student_{$enrollment->student_id}",
                    'dancer_name' => $enrollment->student->fullName,
                    'course_name' => $course->name,
                    'attendance_rate' => $this->attendanceRate(
                        $counts['attended'] + $counts['late'],
                        $course->events->count(),
                    ),
                    'excused_absences' => $counts['excused_absence'],
                    'unexcused_absences' => $counts['unexcused_absence'],
                ];
            }
        }

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function overallAttendance(User $user, array $filters): ReportDataset
    {
        $headers = [
            'course_name' => 'Course Name',
            'instructor' => 'Instructor',
            'attendance_rate' => 'Attendance Rate',
            'excused_absences' => 'Excused Absences',
            'unexcused_absences' => 'Unexcused Absences',
        ];
        $courses = $this->courseQuery($user, $this->academicTermId($filters))
            ->with([
                'teachers:id,first_name,last_name',
                'enrollments' => fn ($query) => $query->whereNotNull('student_id'),
                'events' => fn ($query) => $query
                    ->whereNull('cancelled_at')
                    ->whereNotNull('start_time')
                    ->where('start_time', '<=', now())
                    ->with('attendees'),
            ])
            ->orderBy('name')
            ->get();
        $rows = $courses->map(function (Course $course): array {
            $studentIds = $course->enrollments->pluck('student_id')->filter()->unique();
            $attendees = $course->events
                ->flatMap->attendees
                ->where('attendee_type', (new Student)->getMorphClass())
                ->whereIn('attendee_id', $studentIds);
            $counts = $this->attendanceCounts($attendees);
            $opportunities = $course->events->count() * $studentIds->count();

            return [
                '_key' => "course_{$course->id}",
                'course_name' => $course->name,
                'instructor' => $this->assignedInstructorNames($course),
                'attendance_rate' => $this->attendanceRate(
                    $counts['attended'] + $counts['late'],
                    $opportunities,
                ),
                'excused_absences' => $counts['excused_absence'],
                'unexcused_absences' => $counts['unexcused_absence'],
            ];
        })->all();

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function instructorSubReport(User $user, array $filters): ReportDataset
    {
        $headers = [
            'original_instructor' => 'Original Instructor',
            'course_name' => 'Course',
            'event_date' => 'Event Date',
            'reason' => 'Reason',
            'substitute_instructor' => 'Sub Instructor Name',
        ];
        $events = $this->eventQuery($user, $filters)
            ->whereHas('substituteCoverages')
            ->with(['substituteCoverages.coveredTeacher', 'substituteCoverages.substituteTeacher', 'substituteCoverages.requests'])
            ->get();
        $rows = $events->flatMap(fn (Event $event): Collection => $event->substituteCoverages
            ->map(fn (EventSubstituteCoverage $coverage): array => [
                '_key' => "coverage_{$coverage->id}",
                'original_instructor' => $coverage->coveredTeacherName(),
                'course_name' => $this->eventCourseName($event),
                'event_date' => $this->formatDateTime($event->start_time),
                'reason' => $this->substituteCoverageReason($coverage),
                'substitute_instructor' => $coverage->substituteTeacherName() ?? '—',
            ]))
            ->values()
            ->all();

        return new ReportDataset($headers, $rows);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{
     *     event_id: int,
     *     instructor_key: string,
     *     instructor_name: string,
     *     starts_at: CarbonInterface,
     *     ends_at: CarbonInterface,
     *     course_name: string,
     *     enrollment_count: int,
     *     academic_term: string,
     *     role: string,
     *     hours: float,
     *     status: string
     * }>
     */
    private function teachingRows(User $user, array $filters): array
    {
        $instructorId = $this->instructorFilterId($filters, $user);
        $rows = [];

        foreach ($this->eventQuery($user, $filters)->get() as $event) {
            foreach ($this->creditedInstructors($event, $user) as $instructor) {
                if ($instructorId !== null && $instructor['user_id'] !== $instructorId) {
                    continue;
                }

                $startsAt = $event->start_time->copy()->timezone($this->displayTimezone());
                $endsAt = $event->end_time->copy()->timezone($this->displayTimezone());
                $rows[] = [
                    'event_id' => $event->id,
                    'instructor_key' => $instructor['key'],
                    'instructor_name' => $instructor['name'],
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'course_name' => $this->eventCourseName($event),
                    'enrollment_count' => $event->course_id !== null ? (int) $event->course->enrollments_count : 0,
                    'academic_term' => $this->eventAcademicTermName($event),
                    'role' => $instructor['role'],
                    'hours' => $this->durationHours($event),
                    'status' => $this->eventStatus($event),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Event>
     */
    private function eventQuery(User $user, array $filters): Builder
    {
        [$startsAt, $endsAt] = $this->dateRange($filters);
        $termId = $this->integerFilterValue($filters, 'academic_term_id');
        $term = $termId === null ? null : AcademicTerm::query()->find($termId);
        $termStartsAt = $term instanceof AcademicTerm
            ? CarbonImmutable::parse($term->starts_on->toDateString(), $this->displayTimezone())
                ->startOfDay()
                ->timezone((string) config('app.timezone', 'UTC'))
            : null;
        $termEndsAt = $term instanceof AcademicTerm
            ? CarbonImmutable::parse($term->ends_on->toDateString(), $this->displayTimezone())
                ->endOfDay()
                ->timezone((string) config('app.timezone', 'UTC'))
            : null;
        $query = Event::query()
            ->whereNull('cancelled_at')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereBetween('start_time', [$startsAt, $endsAt])
            ->when($termId !== null, fn (Builder $query): Builder => $query
                ->where(function (Builder $query) use ($termEndsAt, $termId, $termStartsAt): void {
                    $query
                        ->where(function (Builder $query) use ($termEndsAt, $termStartsAt): void {
                            $query
                                ->whereNull('course_id')
                                ->when(
                                    $termStartsAt instanceof CarbonInterface && $termEndsAt instanceof CarbonInterface,
                                    fn (Builder $query): Builder => $query->whereBetween('start_time', [$termStartsAt, $termEndsAt]),
                                );
                        })
                        ->orWhereHas('course', fn (Builder $query): Builder => $query
                            ->where('academic_term_id', $termId));
                }))
            ->with([
                'course' => function (Relation $relation): void {
                    $relation->getQuery()->withCount('enrollments');
                },
                'course.academicTerm',
                'teachers:id,first_name,last_name',
                'substituteCoverages.coveredTeacher:id,first_name,last_name',
                'substituteCoverages.substituteTeacher:id,first_name,last_name',
            ])
            ->orderBy('start_time')
            ->orderBy('id');

        return $this->applyEventAccessConstraint($query, $user);
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function applyEventAccessConstraint(Builder $query, User $user): Builder
    {
        return $user->hasCourseRestrictedAdminAccess()
            ? Event::applyAdminUserViewConstraint($query, $user)
            : $query;
    }

    /** @return list<array{key: string, user_id: int|null, name: string, role: string}> */
    private function creditedInstructors(Event $event, User $user): array
    {
        $coveredTeacherIds = $event->substituteCoverages
            ->filter(fn (EventSubstituteCoverage $coverage): bool => $coverage->isActive()
                || $coverage->substitute_teacher_id !== null)
            ->pluck('covered_teacher_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id);
        $instructors = $event->teachers
            ->reject(fn (User $instructor): bool => $coveredTeacherIds->contains($instructor->id))
            ->map(fn (User $instructor): array => [
                'key' => "user_{$instructor->id}",
                'user_id' => $instructor->id,
                'name' => $instructor->fullName,
                'role' => 'Assigned',
            ])
            ->values()
            ->all();

        foreach ($event->substituteCoverages->whereNotNull('substitute_teacher_id') as $coverage) {
            if (! $coverage->substituteTeacher instanceof User) {
                continue;
            }

            $instructors[] = [
                'key' => "user_{$coverage->substituteTeacher->id}",
                'user_id' => $coverage->substituteTeacher->id,
                'name' => $coverage->substituteTeacher->fullName,
                'role' => 'Substitute',
            ];
        }

        $instructors = collect($instructors)
            ->unique(fn (array $instructor): string => $instructor['key'])
            ->values()
            ->all();

        if ($instructors === [] && $event->course_id !== null && filled($event->course->guest_teacher)) {
            $instructors = [[
                'key' => 'guest_'.md5(mb_strtolower((string) $event->course->guest_teacher)),
                'user_id' => null,
                'name' => (string) $event->course->guest_teacher,
                'role' => 'Guest',
            ]];
        }

        if ($instructors === [] && ! $user->hasCourseRestrictedAdminAccess()) {
            $instructors[] = [
                'key' => 'unassigned',
                'user_id' => null,
                'name' => 'Unassigned',
                'role' => 'Unassigned',
            ];
        }

        return $user->hasCourseRestrictedAdminAccess()
            ? array_values(array_filter(
                $instructors,
                fn (array $instructor): bool => $instructor['user_id'] === $user->id,
            ))
            : $instructors;
    }

    /** @return list<array{key: string, user_id: int|null, name: string, role: string}> */
    private function courseAssignmentInstructors(Course $course, User $user): array
    {
        if (filled($course->guest_teacher)) {
            $instructors = [[
                'key' => 'guest_'.md5(mb_strtolower((string) $course->guest_teacher)),
                'user_id' => null,
                'name' => (string) $course->guest_teacher,
                'role' => 'Guest',
            ]];
        } else {
            $instructors = $course->teachers
                ->map(fn (User $instructor): array => [
                    'key' => "user_{$instructor->id}",
                    'user_id' => $instructor->id,
                    'name' => $instructor->fullName,
                    'role' => 'Assigned',
                ])
                ->values()
                ->all();
        }

        if ($instructors === [] && ! $user->hasCourseRestrictedAdminAccess()) {
            $instructors[] = [
                'key' => 'unassigned',
                'user_id' => null,
                'name' => 'Unassigned',
                'role' => 'Unassigned',
            ];
        }

        return $user->hasCourseRestrictedAdminAccess()
            ? array_values(array_filter(
                $instructors,
                fn (array $instructor): bool => $instructor['user_id'] === $user->id,
            ))
            : $instructors;
    }

    private function assignedInstructorNames(Course $course): string
    {
        if (filled($course->guest_teacher)) {
            return (string) $course->guest_teacher;
        }

        $names = $course->teachers->pluck('fullName')->filter()->join(', ');

        return filled($names) ? $names : 'Unassigned';
    }

    private function assignedEventInstructorNames(Event $event): string
    {
        $names = $event->teachers->pluck('fullName')->filter()->join(', ');

        if (filled($names)) {
            return $names;
        }

        return $event->course_id !== null && filled($event->course->guest_teacher)
            ? (string) $event->course->guest_teacher
            : 'Unassigned';
    }

    /** @return array{value: string, label: string} */
    private function coverageSlotStatus(EventSubstituteCoverage $coverage): array
    {
        if ($coverage->currentSubstituteRequest()?->hasReleaseRequest() === true) {
            $status = EventSubstituteCoverageStatus::ReleaseRequested;
        } elseif ($coverage->substitute_teacher_id === null && $coverage->pendingRequest() instanceof \App\Models\EventSubstituteRequest) {
            $status = EventSubstituteCoverageStatus::AwaitingResponse;
        } elseif ($coverage->substitute_teacher_id === null && $coverage->isActive()) {
            $status = EventSubstituteCoverageStatus::NeedsSubstitute;
        } elseif ($coverage->substitute_teacher_id !== null && $coverage->pendingRequest() instanceof \App\Models\EventSubstituteRequest) {
            $status = EventSubstituteCoverageStatus::ReplacementPending;
        } elseif ($coverage->substitute_teacher_id !== null) {
            $status = EventSubstituteCoverageStatus::Confirmed;
        } else {
            $status = EventSubstituteCoverageStatus::NotNeeded;
        }

        return [
            'value' => $status->value,
            'label' => $status->getLabel(),
        ];
    }

    private function substituteCoverageReason(EventSubstituteCoverage $coverage): string
    {
        $requests = $coverage->requests->sortByDesc('id');
        $reason = $requests->pluck('request_reason')->first(fn (mixed $reason): bool => filled($reason))
            ?? $requests->pluck('release_reason')->first(fn (mixed $reason): bool => filled($reason))
            ?? $requests->pluck('closure_reason')->first(fn (mixed $reason): bool => filled($reason))
            ?? $coverage->closure_reason;

        return filled($reason) ? (string) $reason : '—';
    }

    private function eventCourseName(Event $event): string
    {
        return $event->course_id !== null ? $event->course->name : $event->name;
    }

    private function eventAcademicTermName(Event $event): string
    {
        return $event->course_id !== null
            ? ($event->course->academicTerm->display_name ?? 'Unassigned')
            : 'Standalone';
    }

    private function additionalInstructorNames(
        Course $course,
        ?int $instructorId,
        string $instructorName,
    ): string {
        $names = $course->teachers
            ->reject(fn (User $instructor): bool => $instructor->id === $instructorId)
            ->pluck('fullName')
            ->filter();

        if (filled($course->guest_teacher) && $course->guest_teacher !== $instructorName) {
            $names->push((string) $course->guest_teacher);
        }

        return $names->unique()->join(', ');
    }

    /** @return Builder<Course> */
    private function courseQuery(User $user, ?int $termId = null): Builder
    {
        return Course::query()
            ->when($termId !== null, fn (Builder $query): Builder => $query
                ->where('academic_term_id', $termId))
            ->when($user->hasCourseRestrictedAdminAccess(), fn (Builder $query): Builder => $query
                ->whereHas('teachers', fn (Builder $query): Builder => $query->whereKey($user->id)));
    }

    /** @param array<string, mixed> $filters */
    private function selectedCourse(User $user, array $filters): ?Course
    {
        $courseId = $this->integerFilterValue($filters, 'course_id');
        $query = $this->courseQuery($user, $this->academicTermId($filters));

        return $courseId === null
            ? $query->orderBy('name')->orderBy('id')->first()
            : $query->whereKey($courseId)->first();
    }

    /** @param array<string, mixed> $filters */
    private function academicTermId(array $filters): ?int
    {
        return $this->integerFilterValue($filters, 'academic_term_id')
            ?? $this->currentTerm()?->id;
    }

    /** @param array<string, mixed> $filters */
    private function competitionSeasonId(array $filters): ?int
    {
        return $this->integerFilterValue($filters, 'competition_season_id')
            ?? $this->currentCompetitionSeason()?->id;
    }

    private function mediaReleaseStatus(Student $student): string
    {
        $waiver = $this->studentProfiles->medicalWaiver($student);

        if (! $waiver instanceof StudentWaiver) {
            return 'Pending';
        }

        return $waiver->media_release_consent
            ? 'On File — Approved'
            : 'On File — Declined';
    }

    /** @return array<string, string> */
    private function emergencyContactHeaders(int $maximumContacts): array
    {
        $headers = [];

        for ($number = 1; $number <= $maximumContacts; $number++) {
            $headers["emergency_contact_{$number}_name"] = "Emergency Contact #{$number} Name";
            $headers["emergency_contact_{$number}_phone"] = "Emergency Contact #{$number} Phone";
            $headers["emergency_contact_{$number}_email"] = "Emergency Contact #{$number} Email";
        }

        return $headers;
    }

    /**
     * @param  Collection<int, EventAttendee>  $attendees
     * @return array{attended: int, late: int, excused_absence: int, unexcused_absence: int}
     */
    private function attendanceCounts(Collection $attendees): array
    {
        return [
            'attended' => $attendees->where('status', AttendanceStatus::Present)->count(),
            'late' => $attendees->where('status', AttendanceStatus::Late)->count(),
            'excused_absence' => $attendees->where('status', AttendanceStatus::ExcusedAbsence)->count(),
            'unexcused_absence' => $attendees->where('status', AttendanceStatus::UnexcusedAbsence)->count(),
        ];
    }

    private function attendanceRate(int $attended, int $opportunities): string
    {
        return $opportunities === 0
            ? '—'
            : number_format(($attended / $opportunities) * 100, 1).'%';
    }

    private function percentageValue(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round(($numerator / $denominator) * 100, 1);
    }

    /** @param array<string, mixed> $filters */
    private function dateRange(array $filters): array
    {
        $filter = is_array($filters['date_range'] ?? null) ? $filters['date_range'] : [];
        $termId = $this->integerFilterValue($filters, 'academic_term_id');
        $term = $termId === null ? null : AcademicTerm::query()->find($termId);
        $from = $this->validDate($filter['from'] ?? null)
            ?? $term?->starts_on->toDateString()
            ?? $this->defaultDateFrom();
        $through = $this->validDate($filter['through'] ?? null)
            ?? $term?->ends_on->toDateString()
            ?? $this->defaultDateThrough();
        $startsAt = CarbonImmutable::parse($from, $this->displayTimezone())->startOfDay();
        $endsAt = CarbonImmutable::parse($through, $this->displayTimezone())->endOfDay();

        if ($endsAt->lt($startsAt)) {
            [$startsAt, $endsAt] = [$endsAt->startOfDay(), $startsAt->endOfDay()];
        }

        $storageTimezone = (string) config('app.timezone', 'UTC');

        return [$startsAt->setTimezone($storageTimezone), $endsAt->setTimezone($storageTimezone)];
    }

    private function eventStatus(Event $event): string
    {
        if ($event->end_time->lt(now())) {
            return 'Completed';
        }

        return $event->start_time->lte(now()) ? 'In Progress' : 'Upcoming';
    }

    private function durationHours(Event $event): float
    {
        return round(max(0.0, $event->start_time->diffInMinutes($event->end_time) / 60), 2);
    }

    private function formatDateTime(?CarbonInterface $dateTime): string
    {
        return $dateTime?->copy()->timezone($this->displayTimezone())->format('M j, Y g:i A') ?? '—';
    }

    /** @param array<string, mixed> $filters */
    private function instructorFilterId(array $filters, User $user): ?int
    {
        return $user->hasCourseRestrictedAdminAccess()
            ? $user->id
            : $this->integerFilterValue($filters, 'instructor_id');
    }

    /** @param array<string, mixed> $filters */
    private function integerFilterValue(array $filters, string $name): ?int
    {
        $id = filter_var($this->filterValue($filters, $name), FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }

    /** @param array<string, mixed> $filters */
    private function filterValue(array $filters, string $name): mixed
    {
        $filter = $filters[$name] ?? null;

        return is_array($filter) ? ($filter['value'] ?? null) : $filter;
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->displayTimezone());

        return $date?->toDateString() === $value ? $value : null;
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone', 'UTC'));
    }

    /**
     * @return array{
     *     instructor_count: int,
     *     scheduled_event_count: int,
     *     scheduled_hours: float,
     *     completed_hours: float,
     *     substitute_event_count: int,
     *     substitute_hours: float,
     *     needs_coverage_count: int,
     *     cancelled_event_count: int,
     *     overall_attendance_rate: float|null,
     *     overall_sub_rate: float|null
     * }
     */
    private function emptyDashboard(): array
    {
        return [
            'instructor_count' => 0,
            'scheduled_event_count' => 0,
            'scheduled_hours' => 0.0,
            'completed_hours' => 0.0,
            'substitute_event_count' => 0,
            'substitute_hours' => 0.0,
            'needs_coverage_count' => 0,
            'cancelled_event_count' => 0,
            'overall_attendance_rate' => null,
            'overall_sub_rate' => null,
        ];
    }
}
