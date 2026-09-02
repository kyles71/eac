<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\FormTypes;
use App\Enums\ReportCategory;
use App\Enums\ReportExportFormat;
use App\Enums\ReportKey;
use App\Enums\ReportWidgetKey;
use App\Filament\Admin\Pages\Reports\ClassSafetyRoster;
use App\Filament\Admin\Pages\Reports\EmergencyTextsByCourse;
use App\Filament\Admin\Pages\Reports\InstructorClassAssignments;
use App\Filament\Admin\Pages\Reports\InstructorHoursSummary;
use App\Filament\Admin\Pages\Reports\InstructorReports;
use App\Filament\Admin\Pages\Reports\InstructorSubReport;
use App\Filament\Admin\Pages\Reports\InstructorTeachingSchedule;
use App\Filament\Admin\Pages\Reports\SubstituteCoverage;
use App\Filament\Admin\Widgets\Reports\InstructorOverview;
use App\Models\AcademicTerm;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\EmergencyContact;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventSubstituteRequest;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\ReportExport;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Services\Reports\InstructorReportService;
use App\Services\Reports\ReportExportService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Storage::fake('local');
});

it('grants instructor reports to owners and teachers', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();

    expect(ReportCategory::Instructor->canView($owner))->toBeTrue()
        ->and(ReportCategory::Instructor->canView($teacher))->toBeTrue()
        ->and(ReportWidgetKey::InstructorOverview->canView($owner))->toBeTrue()
        ->and(ReportWidgetKey::InstructorOverview->canView($teacher))->toBeTrue();

    foreach ([
        ReportKey::InstructorClassAssignments,
        ReportKey::InstructorTeachingSchedule,
        ReportKey::InstructorHoursSummary,
        ReportKey::SubstituteCoverage,
    ] as $report) {
        expect($report->canView($owner))->toBeTrue()
            ->and($report->canView($teacher))->toBeTrue();
    }

    expect(ReportKey::ClassRosters->canView($teacher))->toBeTrue()
        ->and(ReportKey::InstructorSchedule->canView($teacher))->toBeTrue()
        ->and(ReportKey::ClassAttendance->canView($teacher))->toBeTrue()
        ->and(ReportKey::ClassSafetyRoster->canView($teacher))->toBeFalse()
        ->and(ReportKey::InstructorSubReport->canView($teacher))->toBeFalse();

    $this->actingAs($teacher);
    $this->get(ClassSafetyRoster::getUrl(panel: 'admin'))->assertForbidden();
    $this->get(InstructorSubReport::getUrl(panel: 'admin'))->assertForbidden();
});

it('allows the instructor widget independently of its linked reports', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(ReportWidgetKey::InstructorOverview->permission());
    $this->actingAs($user);

    expect(ReportCategory::Instructor->canView($user))->toBeTrue()
        ->and(InstructorReports::canAccess())->toBeTrue()
        ->and(InstructorOverview::canView())->toBeTrue()
        ->and(ReportKey::InstructorHoursSummary->canView($user))->toBeFalse();

    $this->get(InstructorReports::getUrl(panel: 'admin'))->assertOk();
    $this->get(InstructorHoursSummary::getUrl(panel: 'admin'))->assertForbidden();
});

it('renders the instructor report category and assignment report', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Avery',
        'last_name' => 'Teacher',
    ]);
    $course = Course::factory()->for($term)->create(['name' => 'Advanced Jazz']);
    $course->teachers()->sync([$teacher->id]);
    $this->actingAs($owner);

    livewire(InstructorReports::class)
        ->assertOk()
        ->assertSee('Class Rosters')
        ->assertSee('Class Safety Roster')
        ->assertSee('Class Attendance Report')
        ->assertSee('Instructor Class Assignments')
        ->assertSee('Instructor Teaching Schedule')
        ->assertSee('Instructor Hours Summary')
        ->assertSee('Substitute Coverage');

    livewire(InstructorClassAssignments::class)
        ->loadTable()
        ->filterTable('academic_term_id', $term->id)
        ->assertSee('Advanced Jazz')
        ->assertSee('Avery Teacher');
});

it('renders the academic term selector before the instructor dashboard widgets', function (): void {
    $this->actingAs(User::factory()->isOwner()->create());

    livewire(InstructorReports::class)
        ->assertOk()
        ->assertSeeInOrder([
            'Dashboard Academic Term',
            'Instructor Class Assignments',
        ]);
});

it('builds class roster, safety, and emergency text reports from current waiver data', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    Course::factory()->for($term)->create(['name' => 'Aardvark Basics']);
    $course = Course::factory()->for($term)->create(['name' => 'Safety Ballet']);
    $guardian = User::factory()->create([
        'first_name' => 'Grace',
        'last_name' => 'Guardian',
    ]);
    $student = Student::factory()->for($guardian)->create([
        'first_name' => 'Avery',
        'last_name' => 'Dancer',
    ]);
    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $guardian->id,
        'student_id' => $student->id,
    ]);
    $additionalCourse = Course::factory()->for($term)->create(['name' => 'Zebra Jazz']);
    Enrollment::factory()->create([
        'course_id' => $additionalCourse->id,
        'user_id' => $guardian->id,
        'student_id' => $student->id,
    ]);
    $waiver = StudentWaiver::factory()->create([
        'allergies' => 'Peanuts',
        'medical_conditions' => 'Asthma',
        'medications' => 'Inhaler',
        'behavioral_notes' => 'Quiet space helps',
        'media_release_consent' => true,
    ]);
    $form = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'valid_until' => null,
    ]);
    FormUser::factory()->for($form)->for($guardian)->forStudent($student)->for($waiver, 'responseable')->create();
    EmergencyContact::factory()->for($waiver)->create([
        'name' => 'First Contact',
        'phone_number' => '555-1111',
        'email' => 'first@example.com',
        'wants_text_updates' => true,
    ]);
    EmergencyContact::factory()->for($waiver)->create([
        'name' => 'Second Contact',
        'phone_number' => '555-2222',
        'email' => 'second@example.com',
        'wants_text_updates' => false,
    ]);
    $filters = [
        'academic_term_id' => ['value' => $term->id],
        'course_id' => ['value' => $course->id],
    ];
    $service = app(InstructorReportService::class);
    $roster = $service->dataset(ReportKey::ClassRosters, $owner, $filters);
    $safety = $service->dataset(ReportKey::ClassSafetyRoster, $owner, $filters);
    $texts = $service->dataset(ReportKey::EmergencyTextsByCourse, $owner, $filters);
    $termTexts = $service->dataset(ReportKey::EmergencyTextsByCourse, $owner, [
        'academic_term_id' => ['value' => $term->id],
    ]);
    $unfilteredTexts = $service->dataset(ReportKey::EmergencyTextsByCourse, $owner, []);

    expect($roster->rows[0])->toMatchArray([
        'dancer_name' => 'Avery Dancer',
        'media_release' => 'On File — Approved',
    ])->and($safety->headers)->toHaveKeys([
        'emergency_contact_1_name',
        'emergency_contact_2_name',
    ])->and($safety->rows[0])->toMatchArray([
        'dancer_name' => 'Avery Dancer',
        'user_name' => 'Grace Guardian',
        'emergency_contact_1_name' => 'First Contact',
        'emergency_contact_2_name' => 'Second Contact',
        'allergies' => 'Peanuts',
        'medical_conditions' => 'Asthma',
        'medications' => 'Inhaler',
        'behavioral_notes' => 'Quiet space helps',
    ])->and($texts->rows[0])->toMatchArray([
        'dancer_name' => 'Avery Dancer',
        'emergency_contact_name' => 'First Contact',
        'phone_number' => '555-1111',
    ])->and($texts->headers)->toBe([
        'dancer_name' => 'Dancer Name',
        'emergency_contact_name' => 'Emergency Contact Name',
        'phone_number' => 'Phone Number',
    ])
        ->and($texts->rows[0])->not->toContain('555-2222')
        ->and($termTexts->rows)->toHaveCount(1)
        ->and($termTexts->rows[0]['dancer_name'])->toBe('Avery Dancer')
        ->and($unfilteredTexts->rows)->toHaveCount(1)
        ->and($unfilteredTexts->rows[0]['dancer_name'])->toBe('Avery Dancer');

    $this->actingAs($owner);

    livewire(EmergencyTextsByCourse::class)
        ->loadTable()
        ->assertSee('Avery Dancer')
        ->assertSee('First Contact')
        ->assertSee('555-1111');

    livewire(EmergencyTextsByCourse::class)
        ->loadTable()
        ->filterTable('academic_term_id', $term->id)
        ->assertSee('Emergency Contact Name')
        ->assertSee('First Contact')
        ->assertSee('Phone Number')
        ->assertSee('555-1111')
        ->assertDontSee('555-2222');
});

it('calculates class, competition, and overall attendance from held non-cancelled events', function (): void {
    $this->travelTo('2040-11-01 12:00:00');
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();
    $term = AcademicTerm::factory()->create([
        'year' => 2040,
        'starts_on' => '2040-09-01',
        'ends_on' => '2040-12-31',
    ]);
    $course = Course::factory()->for($term)->create(['name' => 'Competition Jazz']);
    $course->teachers()->sync([$teacher->id]);
    $student = Student::factory()->create([
        'first_name' => 'Jordan',
        'last_name' => 'Dancer',
    ]);
    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
        'student_id' => $student->id,
    ]);
    $season = CompetitionSeason::factory()->create([
        'name' => '2040 Competition',
        'starts_on' => '2040-01-01',
        'ends_on' => '2040-12-31',
    ]);
    $team = CompetitionTeam::factory()->for($season, 'season')->create();
    $team->students()->attach($student);
    $presentEvent = Event::factory()->for($course)->create([
        'start_time' => '2040-10-01 14:00:00',
        'end_time' => '2040-10-01 15:00:00',
    ]);
    $excusedEvent = Event::factory()->for($course)->create([
        'start_time' => '2040-10-08 14:00:00',
        'end_time' => '2040-10-08 15:00:00',
    ]);
    Event::factory()->for($course)->create([
        'start_time' => '2040-10-15 14:00:00',
        'end_time' => '2040-10-15 15:00:00',
        'cancelled_at' => '2040-10-14 12:00:00',
    ]);
    EventAttendee::factory()->forStudent($student)->for($presentEvent)->create([
        'status' => AttendanceStatus::Present,
    ]);
    EventAttendee::factory()->forStudent($student)->for($excusedEvent)->create([
        'status' => AttendanceStatus::ExcusedAbsence,
    ]);
    $service = app(InstructorReportService::class);
    $class = $service->dataset(ReportKey::ClassAttendance, $owner, [
        'academic_term_id' => ['value' => $term->id],
        'course_id' => ['value' => $course->id],
        'date_range' => ['from' => '2040-09-01', 'through' => '2040-12-31'],
    ]);
    $competition = $service->dataset(ReportKey::CompetitionAttendance, $owner, [
        'academic_term_id' => ['value' => $term->id],
        'competition_season_id' => ['value' => $season->id],
    ]);
    $overall = $service->dataset(ReportKey::OverallAttendance, $owner, [
        'academic_term_id' => ['value' => $term->id],
    ]);
    $metrics = $service->dashboard($term, $owner);

    expect($class->rows[0])->toMatchArray([
        'attended' => 1,
        'late' => 0,
        'excused_absence' => 1,
        'unexcused_absence' => 0,
    ])->and($competition->rows[0])->toMatchArray([
        'dancer_name' => 'Jordan Dancer',
        'course_name' => 'Competition Jazz',
        'attendance_rate' => '50.0%',
        'excused_absences' => 1,
    ])->and($overall->rows[0])->toMatchArray([
        'course_name' => 'Competition Jazz',
        'attendance_rate' => '50.0%',
        'excused_absences' => 1,
    ])->and($metrics['overall_attendance_rate'])->toBe(50.0)
        ->and($metrics['overall_sub_rate'])->toBe(0.0);
});

it('reports course schedules and owner-only substitute reasons', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Primary',
        'last_name' => 'Teacher',
    ]);
    $coTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Co',
        'last_name' => 'Teacher',
    ]);
    $substitute = User::factory()->isTeacher()->create([
        'first_name' => 'Sub',
        'last_name' => 'Teacher',
    ]);
    $term = AcademicTerm::factory()->create([
        'year' => 2040,
        'starts_on' => '2040-09-01',
        'ends_on' => '2040-12-31',
    ]);
    $course = Course::factory()->for($term)->create(['name' => 'Monday Ballet']);
    $course->teachers()->sync([$teacher->id, $coTeacher->id]);
    Enrollment::factory(2)->for($course)->create();
    $event = Event::factory()->for($course)->create([
        'start_time' => '2040-10-01 14:00:00',
        'end_time' => '2040-10-01 15:30:00',
        'substitute_needed_at' => '2040-09-25 12:00:00',
        'substitute_teacher_id' => $substitute->id,
    ]);
    EventSubstituteRequest::factory()->accepted()->for($event)->for($substitute, 'teacher')->create([
        'requested_by_user_id' => $owner->id,
        'request_reason' => 'Primary teacher is unavailable',
    ]);
    $service = app(InstructorReportService::class);
    $schedule = $service->dataset(ReportKey::InstructorSchedule, $owner, [
        'academic_term_id' => ['value' => $term->id],
        'instructor_id' => ['value' => $teacher->id],
    ]);
    $subReport = $service->dataset(ReportKey::InstructorSubReport, $owner, [
        'academic_term_id' => ['value' => $term->id],
    ]);

    expect($schedule->rows)->toHaveCount(1)
        ->and($schedule->rows[0])->toMatchArray([
            'instructor_name' => 'Primary Teacher',
            'course_name' => 'Monday Ballet',
            'enrollment_count' => 2,
            'additional_instructors' => 'Co Teacher',
        ])->and($subReport->rows[0])->toMatchArray([
            'original_instructor' => 'Original teacher not recorded',
            'course_name' => 'Monday Ballet',
            'reason' => 'Primary teacher is unavailable',
            'substitute_instructor' => 'Sub Teacher',
        ]);
});

it('credits confirmed substitutes, excludes cancelled events, and scopes teacher hours', function (): void {
    $owner = User::factory()->isOwner()->create();
    $owner->assignRole('teacher');
    $superAdmin = User::factory()->isSuperAdmin()->create();
    $superAdmin->assignRole('teacher');
    $assignedTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Assigned',
        'last_name' => 'Teacher',
    ]);
    $substituteTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Substitute',
        'last_name' => 'Teacher',
    ]);
    $otherTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Other',
        'last_name' => 'Teacher',
    ]);
    $term = AcademicTerm::factory()->create([
        'starts_on' => '2040-09-01',
        'ends_on' => '2040-12-31',
    ]);
    $assignedCourse = Course::factory()->for($term)->create(['name' => 'Assigned Ballet']);
    $assignedCourse->teachers()->sync([$assignedTeacher->id]);
    Enrollment::factory(2)->for($assignedCourse)->create();
    $otherCourse = Course::factory()->for($term)->create(['name' => 'Other Tap']);
    $otherCourse->teachers()->sync([$otherTeacher->id]);

    Event::factory()->for($assignedCourse)->create([
        'start_time' => '2040-10-01 14:00:00',
        'end_time' => '2040-10-01 15:30:00',
    ]);
    Event::factory()->for($assignedCourse)->create([
        'start_time' => '2040-10-08 14:00:00',
        'end_time' => '2040-10-08 16:00:00',
        'substitute_teacher_id' => $substituteTeacher->id,
        'substitute_needed_at' => '2040-10-01 12:00:00',
    ]);
    Event::factory()->for($assignedCourse)->create([
        'start_time' => '2040-10-15 14:00:00',
        'end_time' => '2040-10-15 17:00:00',
        'cancelled_at' => '2040-10-14 12:00:00',
    ]);
    Event::factory()->for($otherCourse)->create([
        'start_time' => '2040-10-02 14:00:00',
        'end_time' => '2040-10-02 15:00:00',
    ]);
    $filters = instructorReportFilters($term);
    $service = app(InstructorReportService::class);

    $ownerSchedule = $service->dataset(ReportKey::InstructorTeachingSchedule, $owner, $filters);
    $ownerHours = $service->dataset(ReportKey::InstructorHoursSummary, $owner, $filters);
    $assignedHours = $service->dataset(ReportKey::InstructorHoursSummary, $assignedTeacher, $filters);
    $substituteHours = $service->dataset(ReportKey::InstructorHoursSummary, $substituteTeacher, $filters);

    expect($owner->hasCourseRestrictedAdminAccess())->toBeFalse()
        ->and($superAdmin->hasCourseRestrictedAdminAccess())->toBeFalse()
        ->and($ownerSchedule->headers)->not->toHaveKey('day_of_week')
        ->and($ownerSchedule->headers)->toMatchArray([
            'enrollment_count' => 'Number of Enrollments',
        ])->and($ownerSchedule->rows)->toHaveCount(3)
        ->and($ownerSchedule->rows[0])->toMatchArray([
            'date' => '2040-10-01',
            'course_name' => 'Assigned Ballet',
            'enrollment_count' => 2,
        ])
        ->and(collect($ownerSchedule->rows)->pluck('instructor_name')->all())
        ->toContain('Assigned Teacher', 'Substitute Teacher', 'Other Teacher')
        ->and(collect($ownerSchedule->rows)->where('course_name', 'Assigned Ballet')->sum('hours'))->toBe(3.5)
        ->and(collect($ownerSchedule->rows)->where('role', 'Substitute')->first()['instructor_name'])
        ->toBe('Substitute Teacher')
        ->and(collect($ownerHours->rows)->sum('scheduled_hours'))->toBe(4.5)
        ->and($assignedHours->rows)->toHaveCount(1)
        ->and($assignedHours->rows[0])->toMatchArray([
            'scheduled_hours' => 1.5,
            'sub_hours_needed' => 2.0,
            'sub_hours_covered' => 0.0,
        ])
        ->and($substituteHours->rows)->toHaveCount(1)
        ->and($substituteHours->rows[0])->toMatchArray([
            'instructor_name' => 'Substitute Teacher',
            'scheduled_hours' => 2.0,
            'sub_hours_needed' => 0.0,
            'sub_hours_covered' => 2.0,
        ])->and($ownerHours->headers)->not->toHaveKey('substitute_event_count')
        ->and($substituteHours->rows[0])->not->toHaveKey('substitute_event_count')
        ->and($ownerHours->headers)->toMatchArray([
            'sub_hours_needed' => 'Sub Hours Needed',
            'sub_hours_covered' => 'Sub Hours Covered',
        ]);

    $this->actingAs($owner);

    $teachingSchedulePage = livewire(InstructorTeachingSchedule::class)
        ->loadTable()
        ->filterTable('academic_term_id', $term->id)
        ->filterTable('date_range', [
            'from' => '2040-09-01',
            'through' => '2040-12-31',
        ])
        ->assertDontSee('Day of the Week')
        ->assertSee('Number of Enrollments')
        ->assertSee('Monday, 2040-10-01');

    expect($teachingSchedulePage->instance()->getTable()->getColumn('hours')?->isToggledHiddenByDefault())
        ->toBeTrue();

    $hoursSummaryPage = livewire(InstructorHoursSummary::class)
        ->loadTable()
        ->assertDontSee('Substitute Events')
        ->assertSee('Sub Hours Needed')
        ->assertSee('Sub Hours Covered');

    expect($hoursSummaryPage->instance()->getTable()->getColumn('sub_hours_needed')?->getTooltip())
        ->toBe('Scheduled hours from this instructor\'s assigned classes that were marked as needing substitute coverage, whether or not coverage was found.')
        ->and($hoursSummaryPage->instance()->getTable()->getColumn('sub_hours_covered')?->getTooltip())
        ->toBe('Scheduled hours assigned to this instructor as the confirmed substitute. Includes completed and upcoming non-cancelled events.');
});

it('reports substitute coverage and dashboard exceptions without counting cancelled hours', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();
    $substitute = User::factory()->isTeacher()->create([
        'first_name' => 'Casey',
        'last_name' => 'Substitute',
    ]);
    $term = AcademicTerm::factory()->create([
        'starts_on' => '2040-09-01',
        'ends_on' => '2040-12-31',
    ]);
    $course = Course::factory()->for($term)->create(['name' => 'Modern']);
    $course->teachers()->sync([$teacher->id]);

    $needsCoverageEvent = Event::factory()->for($course)->create([
        'start_time' => '2040-10-01 14:00:00',
        'end_time' => '2040-10-01 15:00:00',
        'substitute_needed_at' => '2040-09-25 12:00:00',
    ]);
    $confirmedEvent = Event::factory()->for($course)->create([
        'start_time' => '2040-10-08 14:00:00',
        'end_time' => '2040-10-08 15:30:00',
        'substitute_needed_at' => '2040-09-25 12:00:00',
        'substitute_teacher_id' => $substitute->id,
    ]);
    EventSubstituteRequest::factory()
        ->declined()
        ->for($needsCoverageEvent)
        ->create([
            'requested_by_user_id' => $owner->id,
            'request_reason' => 'Instructor is sick',
        ]);
    EventSubstituteRequest::factory()
        ->accepted()
        ->for($confirmedEvent)
        ->for($substitute, 'teacher')
        ->create([
            'requested_by_user_id' => $owner->id,
            'request_reason' => 'Instructor is traveling',
        ]);
    Event::factory()->for($course)->create([
        'start_time' => '2040-10-15 14:00:00',
        'end_time' => '2040-10-15 16:00:00',
        'cancelled_at' => '2040-10-14 12:00:00',
    ]);

    $service = app(InstructorReportService::class);
    $coverage = $service->dataset(
        ReportKey::SubstituteCoverage,
        $owner,
        instructorReportFilters($term),
    );
    $metrics = $service->dashboard($term, $owner);

    expect($coverage->rows)->toHaveCount(2)
        ->and(collect($coverage->rows)->pluck('coverage_status')->all())
        ->toContain('Needs Substitute', 'Confirmed')
        ->and(collect($coverage->rows)->where('coverage_status', 'Needs Substitute')->first()['reason'])
        ->toBe('Instructor is sick')
        ->and(collect($coverage->rows)->where('coverage_status', 'Confirmed')->first()['confirmed_substitute'])
        ->toBe('Casey Substitute')
        ->and(collect($coverage->rows)->where('coverage_status', 'Confirmed')->first()['reason'])
        ->toBe('Instructor is traveling')
        ->and($metrics)->toMatchArray([
            'scheduled_event_count' => 2,
            'scheduled_hours' => 2.5,
            'substitute_event_count' => 1,
            'substitute_hours' => 1.5,
            'needs_coverage_count' => 1,
            'cancelled_event_count' => 1,
        ]);

    $this->actingAs($owner);

    livewire(SubstituteCoverage::class)
        ->loadTable()
        ->filterTable('academic_term_id', $term->id)
        ->filterTable('date_range', [
            'from' => '2040-09-01',
            'through' => '2040-12-31',
        ])
        ->assertSee('Reason')
        ->assertSee('Instructor is sick')
        ->assertSee('Instructor is traveling');
});

it('exports instructor reports through the shared export pipeline', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Export',
        'last_name' => 'Teacher',
    ]);
    $term = AcademicTerm::factory()->create([
        'starts_on' => '2040-09-01',
        'ends_on' => '2040-12-31',
    ]);
    $course = Course::factory()->for($term)->create(['name' => 'Export Ballet']);
    $course->teachers()->sync([$teacher->id]);
    Event::factory()->for($course)->create([
        'start_time' => '2040-10-01 14:00:00',
        'end_time' => '2040-10-01 15:00:00',
    ]);
    $export = ReportExport::factory()->for($owner)->create([
        'report_key' => ReportKey::InstructorHoursSummary,
        'format' => ReportExportFormat::Csv,
        'state' => [
            'filters' => instructorReportFilters($term),
            'columns' => ['instructor_name', 'scheduled_hours'],
        ],
    ]);

    $path = app(ReportExportService::class)->generate($export, $owner);
    $export->refresh();

    expect($export->total_rows)->toBe(2)
        ->and(Storage::disk('local')->get($path))
        ->toContain('Instructor,"Scheduled Hours"')
        ->toContain('"Export Teacher",1');
});

function instructorReportFilters(AcademicTerm $term): array
{
    return [
        'academic_term_id' => ['value' => $term->id],
        'date_range' => [
            'from' => '2040-09-01',
            'through' => '2040-12-31',
        ],
    ];
}
