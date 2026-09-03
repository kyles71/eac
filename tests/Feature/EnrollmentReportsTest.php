<?php

declare(strict_types=1);

use App\Enums\ReportCategory;
use App\Enums\ReportKey;
use App\Enums\ReportWidgetKey;
use App\Filament\Admin\Pages\Reports\CompetitionEmailList;
use App\Filament\Admin\Pages\Reports\CompetitionEnrollments;
use App\Filament\Admin\Pages\Reports\EnrollmentReports;
use App\Filament\Admin\Pages\Reports\EnrollmentsByTerm;
use App\Filament\Admin\Pages\Reports\TotalEnrollmentsByClass;
use App\Filament\Admin\Resources\Enrollments\EnrollmentResource;
use App\Filament\Admin\Widgets\Reports\CapacityMetricChart;
use App\Filament\Admin\Widgets\Reports\EnrollmentOverview;
use App\Filament\Clusters\Settings\Pages\ReportingSettingsPage;
use App\Models\AcademicTerm;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\CourseHoldSeat;
use App\Models\Enrollment;
use App\Models\RecurringPrivateLesson;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use App\Services\Reports\EnrollmentReportService;
use App\Settings\ReportingSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('starts reporting tag settings without guessed tags', function (): void {
    $settings = app(ReportingSettings::class);

    expect($settings->capacity_metrics)->toBe([])
        ->and($settings->excluded_course_tag_slugs)->toBe([]);
});

it('grants owners all enrollment reports and teachers only their class totals', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();

    expect(ReportCategory::Enrollment->canView($owner))->toBeTrue()
        ->and(collect(ReportKey::cases())->every(fn (ReportKey $report): bool => $report->canView($owner)))->toBeTrue()
        ->and(ReportCategory::Enrollment->canView($teacher))->toBeTrue()
        ->and(ReportKey::TotalEnrollmentsByClass->canView($teacher))->toBeTrue()
        ->and(ReportKey::EnrollmentsByTerm->canView($teacher))->toBeFalse()
        ->and(ReportWidgetKey::EnrollmentOverview->permission())->toBe(ReportKey::TotalEnrollmentsByClass->permission())
        ->and(ReportWidgetKey::EnrollmentOverview->canView($teacher))->toBeTrue()
        ->and(ReportWidgetKey::EnrollmentCapacityMetrics->canView($owner))->toBeTrue()
        ->and(ReportWidgetKey::EnrollmentCapacityMetrics->canView($teacher))->toBeTrue();
});

it('allows a widget permission to expose its report category without exposing reports', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(ReportWidgetKey::EnrollmentCapacityMetrics->permission());
    $this->actingAs($user);

    expect(ReportCategory::Enrollment->canView($user))->toBeTrue()
        ->and(EnrollmentReports::canAccess())->toBeTrue()
        ->and(CapacityMetricChart::canView())->toBeTrue()
        ->and(EnrollmentOverview::canView())->toBeFalse()
        ->and(ReportKey::EnrollmentsByTerm->canView($user))->toBeFalse();
});

it('renders a permission-aware enrollment reports landing page', function (): void {
    $owner = User::factory()->isOwner()->create();
    $this->actingAs($owner);

    livewire(EnrollmentReports::class)
        ->assertOk()
        ->assertSee('Enrollment Reports')
        ->assertSee('Enrollments by Term')
        ->assertSee('Competition Email List');

    $teacher = User::factory()->isTeacher()->create();
    $this->actingAs($teacher);

    livewire(EnrollmentReports::class)
        ->assertOk()
        ->assertSee('Total Enrollments by Class')
        ->assertDontSee('Enrollments by Term')
        ->assertDontSee('Competition Email List');

    $this->get(EnrollmentsByTerm::getUrl(panel: 'admin'))->assertForbidden();
});

it('only exposes competition enrollments to teachers assigned to a competition team', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $teacher->givePermissionTo(ReportKey::CompetitionEnrollments->permission());
    $this->actingAs($teacher);

    expect(ReportKey::CompetitionEnrollments->canView($teacher))->toBeFalse();
    $this->get(CompetitionEnrollments::getUrl(panel: 'admin'))->assertForbidden();

    livewire(EnrollmentReports::class)
        ->assertOk()
        ->assertDontSee('Competition Enrollments');

    $season = CompetitionSeason::factory()->create();
    $team = CompetitionTeam::factory()->for($season, 'season')->create();
    $team->staff()->attach($teacher);

    expect(ReportKey::CompetitionEnrollments->canView($teacher))->toBeTrue();
    $this->get(CompetitionEnrollments::getUrl(panel: 'admin'))->assertOk();

    livewire(EnrollmentReports::class)
        ->assertOk()
        ->assertSee('Competition Enrollments');
});

it('renders the academic term selector before the enrollment dashboard widgets', function (): void {
    $this->actingAs(User::factory()->isOwner()->create());

    livewire(EnrollmentReports::class)
        ->assertOk()
        ->assertSeeInOrder([
            'Dashboard Academic Term',
            'Total Enrollments by Class',
        ]);
});

it('lets owners configure enrollment dashboard thresholds but forbids teachers', function (): void {
    $owner = User::factory()->isOwner()->create();
    $course = Course::factory()->create();
    $course->syncTagsWithType(['Ballet', 'Level 1'], Course::GENERAL_TAG_TYPE);
    $this->actingAs($owner);

    livewire(ReportingSettingsPage::class)
        ->fillForm([
            'not_running_maximum_enrollments' => 2,
            'near_sold_out_maximum_remaining' => 5,
            'capacity_metrics' => [[
                'name' => 'Level',
                'tag_slugs' => ['level-1'],
            ]],
            'excluded_course_tag_slugs' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(ReportingSettings::class);

    expect($settings->not_running_maximum_enrollments)->toBe(2)
        ->and($settings->near_sold_out_maximum_remaining)->toBe(5)
        ->and($settings->capacity_metrics)->toBe([[
            'name' => 'Level',
            'tag_slugs' => ['level-1'],
        ]]);

    $teacher = User::factory()->isTeacher()->create();
    $this->actingAs($teacher);

    expect(ReportingSettingsPage::canAccess())->toBeFalse();
    $this->get(ReportingSettingsPage::getUrl(panel: 'admin'))->assertForbidden();
});

it('only retains reporting settings for course tags that exist', function (): void {
    $owner = User::factory()->isOwner()->create();
    $course = Course::factory()->create();
    $course->syncTagsWithType(['Ballet'], Course::GENERAL_TAG_TYPE);
    $settings = app(ReportingSettings::class);
    $settings->capacity_metrics = [
        [
            'name' => 'Style',
            'tag_slugs' => ['ballet', 'missing-capacity-tag'],
        ],
        [
            'name' => 'Missing',
            'tag_slugs' => ['missing-capacity-tag'],
        ],
    ];
    $settings->excluded_course_tag_slugs = ['ballet', 'missing-excluded-tag'];
    $settings->save();
    $this->actingAs($owner);

    livewire(ReportingSettingsPage::class)
        ->assertFormSet(function (array $data): array {
            expect(array_values($data['capacity_metrics']))->toBe([[
                'name' => 'Style',
                'tag_slugs' => ['ballet'],
            ]])->and($data['excluded_course_tag_slugs'])->toBe(['ballet']);

            return [];
        })
        ->assertSee('Ballet')
        ->assertDontSee('Missing Capacity Tag')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(ReportingSettings::class)->capacity_metrics)->toBe([[
        'name' => 'Style',
        'tag_slugs' => ['ballet'],
    ]])->and(app(ReportingSettings::class)->excluded_course_tag_slugs)->toBe(['ballet']);
});

it('renders the class totals report with custom in-memory records', function (): void {
    $term = AcademicTerm::factory()->create();
    Course::factory()->for($term)->create(['name' => 'Ballet 1', 'capacity' => 12]);

    livewire(TotalEnrollmentsByClass::class)
        ->loadTable()
        ->filterTable('academic_term_id', $term->id)
        ->assertSee('Ballet 1')
        ->assertSee('12');
});

it('hydrates dashboard-linked table filters from the URL', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $availableCourse = Course::factory()->for($term)->create([
        'name' => 'Available Ballet',
        'capacity' => 2,
    ]);
    $soldOutCourse = Course::factory()->for($term)->create([
        'name' => 'Sold Out Ballet',
        'capacity' => 1,
    ]);
    Enrollment::factory()->for($soldOutCourse)->create();
    $this->actingAs($owner);

    Livewire::withQueryParams([
        'filters' => [
            'academic_term_id' => ['value' => $term->id],
            'capacity_status' => ['value' => 'sold_out'],
        ],
    ])->test(TotalEnrollmentsByClass::class)
        ->loadTable()
        ->assertSet('tableFilters.academic_term_id.value', $term->id)
        ->assertSet('tableFilters.capacity_status.value', 'sold_out')
        ->assertSee($soldOutCourse->name)
        ->assertDontSee($availableCourse->name);
});

it('links enrollment overview widgets to their detailed views', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $this->actingAs($owner);
    $soldOutUrl = TotalEnrollmentsByClass::getUrlWithFilters([
        'academic_term_id' => ['value' => $term->id],
        'capacity_status' => ['value' => 'sold_out'],
    ]);
    $openEnrollmentsUrl = EnrollmentResource::getUrl('index', ['tab' => 'open']);

    livewire(EnrollmentOverview::class, [
        'pageFilters' => ['academic_term_id' => $term->id],
    ])
        ->assertSee('Sold Out')
        ->assertSee('Near Sold Out')
        ->assertSee('*includes Competition team courses')
        ->assertSeeHtml(e($soldOutUrl))
        ->assertSeeHtml(e($openEnrollmentsUrl));
});

it('renders a bar chart for each configured capacity metric', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create(['capacity' => 10]);
    $course->syncTagsWithType(['Ballet'], Course::GENERAL_TAG_TYPE);
    Enrollment::factory(5)->for($course)->create();
    $settings = app(ReportingSettings::class);
    $settings->capacity_metrics = [[
        'name' => 'Style',
        'tag_slugs' => ['ballet'],
    ]];
    $settings->save();
    $this->actingAs($owner);

    $this->get(EnrollmentReports::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSeeLivewire(CapacityMetricChart::class);

    livewire(CapacityMetricChart::class, [
        'metricName' => 'Style',
        'tagSlugs' => ['ballet'],
        'pageFilters' => ['academic_term_id' => $term->id],
    ])
        ->assertSee('Style')
        ->assertSee('Ballet')
        ->assertSee('Capacity Used (%)')
        ->assertSeeHtml('data-chart-type="bar"');
});

it('builds a dancer matrix with unassigned seats and course totals', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $ballet = Course::factory()->for($term)->create(['name' => 'Ballet 1', 'capacity' => 10]);
    $tap = Course::factory()->for($term)->create(['name' => 'Tap 2', 'capacity' => 8]);
    $guardian = User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
    $student = Student::factory()->for($guardian)->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    Enrollment::factory()->create([
        'course_id' => $ballet->id,
        'user_id' => $guardian->id,
        'student_id' => $student->id,
    ]);
    Enrollment::factory()->create([
        'course_id' => $tap->id,
        'user_id' => $guardian->id,
        'student_id' => $student->id,
    ]);
    Enrollment::factory()->create([
        'course_id' => $tap->id,
        'user_id' => $guardian->id,
        'student_id' => null,
    ]);

    $dataset = app(EnrollmentReportService::class)->dataset(
        ReportKey::EnrollmentsByTerm,
        $owner,
        ['academic_term_id' => ['value' => $term->id]],
    );

    expect($dataset->headers)->toHaveKeys(["course_{$ballet->id}", "course_{$tap->id}"])
        ->and($dataset->rows)->toHaveCount(2)
        ->and($dataset->rows[0]['dancer_name'])->toBe('Ada Lovelace')
        ->and($dataset->rows[0]["course_{$ballet->id}"])->toBe('X')
        ->and($dataset->rows[0]["course_{$tap->id}"])->toBe('X')
        ->and($dataset->rows[1]['dancer_name'])->toBe('Unassigned')
        ->and($dataset->footerRows[0]["course_{$tap->id}"])->toBe(2)
        ->and($dataset->footerRows[1]["course_{$tap->id}"])->toBe(8);
});

it('filters the enrollment matrix to students enrolled in a selected course', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $ballet = Course::factory()->for($term)->create(['name' => 'Ballet 1']);
    $tap = Course::factory()->for($term)->create(['name' => 'Tap 2']);
    $balletGuardian = User::factory()->create();
    $balletStudent = Student::factory()->for($balletGuardian)->create([
        'first_name' => 'Ballet',
        'last_name' => 'Dancer',
    ]);
    $tapGuardian = User::factory()->create();
    $tapStudent = Student::factory()->for($tapGuardian)->create([
        'first_name' => 'Tap',
        'last_name' => 'Dancer',
    ]);
    Enrollment::factory()->withStudent($balletStudent)->create([
        'course_id' => $ballet->id,
        'user_id' => $balletGuardian->id,
    ]);
    Enrollment::factory()->withStudent($balletStudent)->create([
        'course_id' => $tap->id,
        'user_id' => $balletGuardian->id,
    ]);
    Enrollment::factory()->withStudent($tapStudent)->create([
        'course_id' => $tap->id,
        'user_id' => $tapGuardian->id,
    ]);
    Enrollment::factory()->create([
        'course_id' => $ballet->id,
        'user_id' => $balletGuardian->id,
        'student_id' => null,
    ]);

    $dataset = app(EnrollmentReportService::class)->dataset(
        ReportKey::EnrollmentsByTerm,
        $owner,
        [
            'academic_term_id' => ['value' => $term->id],
            'course_id' => ['value' => $ballet->id],
        ],
    );

    expect(ReportKey::EnrollmentsByTerm->allowedFilterNames())->toContain('course_id')
        ->and($dataset->rows)->toHaveCount(1)
        ->and($dataset->rows[0]['dancer_name'])->toBe('Ballet Dancer')
        ->and($dataset->rows[0]["course_{$ballet->id}"])->toBe('X')
        ->and($dataset->rows[0]["course_{$tap->id}"])->toBe('X')
        ->and($dataset->footerRows[0]["course_{$ballet->id}"])->toBe(1)
        ->and($dataset->footerRows[0]["course_{$tap->id}"])->toBe(1);

    $this->actingAs($owner);

    livewire(EnrollmentsByTerm::class)
        ->loadTable()
        ->filterTable('academic_term_id', $term->id)
        ->filterTable('course_id', $ballet->id)
        ->assertSee('Ballet Dancer')
        ->assertDontSee('Tap Dancer');
});

it('shows teachers the same approved enrollment report and widget data as owners', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $term = AcademicTerm::factory()->create([
        'target_enrollments' => 10,
        'stretch_goal_enrollments' => 12,
    ]);
    $assigned = Course::factory()->for($term)->create(['name' => 'Assigned Jazz', 'capacity' => 2]);
    $other = Course::factory()->for($term)->create(['name' => 'Other Jazz', 'capacity' => 20]);
    $assigned->syncTagsWithType(['Kiddo'], Course::GENERAL_TAG_TYPE);
    $other->syncTagsWithType(['Kiddo'], Course::GENERAL_TAG_TYPE);
    $assigned->teachers()->syncWithoutDetaching([$teacher->id]);
    Enrollment::factory(2)->for($assigned)->create();
    Enrollment::factory(4)->for($other)->create();

    $service = app(EnrollmentReportService::class);
    $teacherDataset = $service->dataset(
        ReportKey::TotalEnrollmentsByClass,
        $teacher,
        ['academic_term_id' => ['value' => $term->id]],
    );
    $owner = User::factory()->isOwner()->create();
    $ownerDataset = $service->dataset(
        ReportKey::TotalEnrollmentsByClass,
        $owner,
        ['academic_term_id' => ['value' => $term->id]],
    );
    $teacherMetrics = $service->dashboard($term, $teacher);
    $ownerMetrics = $service->dashboard($term, $owner);
    $teacherTagCapacities = $service->capacityByTags($term, $teacher, ['kiddo']);
    $ownerTagCapacities = $service->capacityByTags($term, $owner, ['kiddo']);

    expect($teacherDataset)->toEqual($ownerDataset)
        ->and($teacherDataset->rows)->toHaveCount(2)
        ->and($teacherMetrics)->toBe($ownerMetrics)
        ->and($teacherMetrics['enrollment_count'])->toBe(6)
        ->and($teacherMetrics['total_capacity'])->toBe(22)
        ->and($teacherMetrics['sold_out_count'])->toBe(1)
        ->and($teacherMetrics['target_remaining'])->toBe(4)
        ->and($teacherTagCapacities)->toBe($ownerTagCapacities)
        ->and($teacherTagCapacities)->toHaveCount(1)
        ->and($teacherTagCapacities[0])->toMatchArray([
            'slug' => 'kiddo',
            'enrollment_count' => 6,
            'capacity' => 22,
            'percentage' => 27.3,
        ]);

    $this->actingAs($teacher);

    livewire(EnrollmentOverview::class, [
        'pageFilters' => ['academic_term_id' => $term->id],
    ])->assertSee('To Enrollment Target');
});

it('includes capacity-reserving holds in sold out and almost sold out calculations', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $soldOut = Course::factory()->for($term)->create(['name' => 'Held Full', 'capacity' => 3]);
    $almostSoldOut = Course::factory()->for($term)->create(['name' => 'Held Almost Full', 'capacity' => 6]);
    Enrollment::factory()->for($soldOut)->create();
    Enrollment::factory()->for($almostSoldOut)->create();
    CourseHoldSeat::factory(2)->for($soldOut)->create();
    CourseHoldSeat::factory(2)->for($almostSoldOut)->create();

    $service = app(EnrollmentReportService::class);
    $metrics = $service->dashboard($term, $owner);
    $soldOutReport = $service->dataset(ReportKey::TotalEnrollmentsByClass, $owner, [
        'academic_term_id' => ['value' => $term->id],
        'capacity_status' => ['value' => 'sold_out'],
    ]);
    $almostSoldOutReport = $service->dataset(ReportKey::TotalEnrollmentsByClass, $owner, [
        'academic_term_id' => ['value' => $term->id],
        'capacity_status' => ['value' => 'near_sold_out'],
    ]);

    expect($metrics['sold_out_count'])->toBe(1)
        ->and($metrics['near_sold_out_count'])->toBe(1)
        ->and($metrics['enrollment_count'])->toBe(6)
        ->and($soldOutReport->headers)->toHaveKey('holds_count', 'Holds')
        ->and($soldOutReport->rows)->toHaveCount(1)
        ->and($soldOutReport->rows[0])->toMatchArray([
            'course_name' => 'Held Full',
            'enrollment_count' => 3,
            'holds_count' => 2,
            'capacity' => 3,
            'available' => 0,
            'utilization' => '100.0%',
        ])
        ->and($almostSoldOutReport->rows)->toHaveCount(1)
        ->and($almostSoldOutReport->rows[0])->toMatchArray([
            'course_name' => 'Held Almost Full',
            'enrollment_count' => 3,
            'holds_count' => 2,
            'capacity' => 6,
            'available' => 3,
            'utilization' => '50.0%',
        ]);
});

it('excludes private and recurring private lessons from enrollment report counts', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $groupCourse = Course::factory()->for($term)->create([
        'name' => 'Group Ballet',
        'capacity' => 10,
    ]);
    $privateLesson = Course::factory()->for($term)->create([
        'name' => 'Recurring Private Ballet',
        'capacity' => 1,
        'is_private' => true,
    ]);
    $groupCourse->syncTagsWithType(['Ballet'], Course::GENERAL_TAG_TYPE);
    $privateLesson->syncTagsWithType(['Ballet'], Course::GENERAL_TAG_TYPE);
    Enrollment::factory(2)->for($groupCourse)->create();
    CourseHoldSeat::factory()->for($groupCourse)->create();
    $privateEnrollment = Enrollment::factory()->for($privateLesson)->create();
    CourseHoldSeat::factory()->for($privateLesson)->create();
    RecurringPrivateLesson::factory()->for($privateLesson)->create();

    $service = app(EnrollmentReportService::class);
    $metrics = $service->dashboard($term, $owner);
    $classTotals = $service->dataset(ReportKey::TotalEnrollmentsByClass, $owner, [
        'academic_term_id' => ['value' => $term->id],
    ]);
    $termMatrix = $service->dataset(ReportKey::EnrollmentsByTerm, $owner, [
        'academic_term_id' => ['value' => $term->id],
    ]);
    $tagCapacity = $service->capacityByTags($term, $owner, ['ballet']);

    expect($metrics['enrollment_count'])->toBe(3)
        ->and($metrics['total_capacity'])->toBe(10)
        ->and($classTotals->rows)->toHaveCount(1)
        ->and($classTotals->rows[0])->toMatchArray([
            'course_name' => 'Group Ballet',
            'enrollment_count' => 3,
            'holds_count' => 1,
        ])
        ->and($termMatrix->headers)->toHaveKey("course_{$groupCourse->id}")
        ->and($termMatrix->headers)->not->toHaveKey("course_{$privateLesson->id}")
        ->and($termMatrix->footerRows[0]["course_{$groupCourse->id}"])->toBe(3)
        ->and($tagCapacity[0])->toMatchArray([
            'enrollment_count' => 3,
            'capacity' => 10,
            'percentage' => 30.0,
        ])
        ->and($privateEnrollment->exists)->toBeTrue();
});

it('calculates each configured tag capacity independently', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $ballet = Course::factory()->for($term)->create(['capacity' => 10]);
    $jazz = Course::factory()->for($term)->create(['capacity' => 20]);
    $ballet->syncTagsWithType(['Ballet', 'Level 1'], Course::GENERAL_TAG_TYPE);
    $jazz->syncTagsWithType(['Jazz', 'Level 1'], Course::GENERAL_TAG_TYPE);
    Enrollment::factory(5)->for($ballet)->create();
    Enrollment::factory(4)->for($jazz)->create();

    $tagCapacities = app(EnrollmentReportService::class)->capacityByTags(
        $term,
        $owner,
        ['ballet', 'jazz', 'level-1'],
    );

    expect($tagCapacities)->toHaveCount(3)
        ->and($tagCapacities[0])->toMatchArray([
            'slug' => 'ballet',
            'enrollment_count' => 5,
            'capacity' => 10,
            'percentage' => 50.0,
        ])->and($tagCapacities[1])->toMatchArray([
            'slug' => 'jazz',
            'enrollment_count' => 4,
            'capacity' => 20,
            'percentage' => 20.0,
        ])->and($tagCapacities[2])->toMatchArray([
            'slug' => 'level-1',
            'enrollment_count' => 9,
            'capacity' => 30,
            'percentage' => 30.0,
        ]);
});

it('omits configured course tags from enrollment dashboard totals', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $included = Course::factory()->for($term)->create(['capacity' => 10]);
    $excluded = Course::factory()->for($term)->create(['capacity' => 20]);
    $excluded->syncTagsWithType(['Élevé'], Course::GENERAL_TAG_TYPE);
    Enrollment::factory()->for($included)->create();
    Enrollment::factory(4)->for($excluded)->create();
    $settings = app(ReportingSettings::class);
    $settings->excluded_course_tag_slugs = ['eleve'];
    $settings->save();

    $metrics = app(EnrollmentReportService::class)->dashboard($term, $owner);

    expect($metrics['enrollment_count'])->toBe(1)
        ->and($metrics['total_capacity'])->toBe(10);
});

it('deduplicates term email addresses case-insensitively and records their sources', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create();
    $guardian = User::factory()->create(['email' => 'Family@example.com']);
    $student = Student::factory()->for($guardian)->create([
        'first_name' => 'Avery',
        'last_name' => 'Dancer',
    ]);
    StudentEmail::factory()->for($student)->create(['email' => 'FAMILY@EXAMPLE.COM']);
    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $guardian->id,
        'student_id' => $student->id,
    ]);

    $dataset = app(EnrollmentReportService::class)->dataset(
        ReportKey::TermEmailList,
        $owner,
        ['academic_term_id' => ['value' => $term->id]],
    );

    expect($dataset->rows)->toHaveCount(1)
        ->and($dataset->rows[0]['email'])->toBe('Family@example.com')
        ->and($dataset->rows[0]['sources'])->toContain('Enrollment user account')
        ->and($dataset->rows[0]['sources'])->toContain('Avery Dancer account')
        ->and($dataset->rows[0]['sources'])->toContain('Avery Dancer additional email');
});

it('builds competition enrollment and email reports from the selected season', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create(['name' => 'Competition Jazz']);
    $season = CompetitionSeason::factory()->current()->create(['name' => '2026 Competition']);
    $team = CompetitionTeam::factory()->for($season, 'season')->create(['name' => 'Senior Team']);
    $otherTeam = CompetitionTeam::factory()->for($season, 'season')->create(['name' => 'Junior Team']);
    $guardian = User::factory()->create(['email' => 'guardian@example.com']);
    $student = Student::factory()->for($guardian)->create([
        'first_name' => 'Taylor',
        'last_name' => 'Swift',
    ]);
    $team->students()->attach($student);
    $otherGuardian = User::factory()->create(['email' => 'other@example.com']);
    $otherStudent = Student::factory()->for($otherGuardian)->create([
        'first_name' => 'Other',
        'last_name' => 'Dancer',
    ]);
    $otherTeam->students()->attach([$student->id, $otherStudent->id]);
    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $guardian->id,
        'student_id' => $student->id,
    ]);

    $service = app(EnrollmentReportService::class);
    $enrollments = $service->dataset(ReportKey::CompetitionEnrollments, $owner, [
        'academic_term_id' => ['value' => $term->id],
        'competition_season_id' => ['value' => $season->id],
    ]);
    $emails = $service->dataset(ReportKey::CompetitionEmailList, $owner, [
        'competition_season_id' => ['value' => $season->id],
        'competition_team_id' => ['value' => $team->id],
    ]);

    expect(ReportKey::CompetitionEmailList->allowedFilterNames())->toContain('competition_team_id')
        ->and($student->competitionTeams()->count())->toBe(2)
        ->and($enrollments->rows)->toHaveCount(2)
        ->and(collect($enrollments->rows)->firstWhere('competition_team', 'Senior Team'))->toMatchArray([
            'dancer_name' => 'Taylor Swift',
            'competition_team' => 'Senior Team',
            'course_name' => 'Competition Jazz',
        ])
        ->and($emails->rows)->toHaveCount(1)
        ->and($emails->rows[0]['email'])->toBe('guardian@example.com')
        ->and($emails->rows[0]['competition_team'])->toBe('Senior Team');

    $this->actingAs($owner);

    livewire(CompetitionEmailList::class)
        ->loadTable()
        ->filterTable('competition_season_id', $season->id)
        ->filterTable('competition_team_id', $team->id)
        ->assertSee('Competition Team')
        ->assertSee('Senior Team')
        ->assertSee('guardian@example.com')
        ->assertDontSee('other@example.com');

    $this->actingAs(User::factory()->isTeacher()->create());
    $this->get(CompetitionEmailList::getUrl(panel: 'admin'))->assertForbidden();
});
