<?php

declare(strict_types=1);

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportKey;
use App\Enums\SavedReportViewVisibility;
use App\Filament\Admin\Pages\Reports\TotalEnrollmentsByClass;
use App\Jobs\GenerateReportExport;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ReportExport;
use App\Models\SavedReportView;
use App\Models\User;
use App\Services\Reports\ReportExportService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Storage::fake('local');
});

it('polls export notifications promptly and excludes downloads from SPA navigation', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->getDatabaseNotificationsPollingInterval())->toBe('15s')
        ->and($panel->getSpaUrlExceptions())->toContain('*/admin/report-exports/*/download*');
});

it('keeps private saved views private while exposing owner templates to staff', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();
    $private = SavedReportView::factory()->for($owner)->create();
    $template = SavedReportView::factory()->for($owner)->create([
        'visibility' => SavedReportViewVisibility::Template,
    ]);

    expect($private->isVisibleTo($owner))->toBeTrue()
        ->and($private->isVisibleTo($teacher))->toBeFalse()
        ->and($template->isVisibleTo($teacher))->toBeTrue()
        ->and(SavedReportView::query()->visibleTo($teacher)->pluck('id')->all())
        ->toBe([$template->id]);
});

it('saves the current report filters as a reusable view', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $this->actingAs($owner);

    livewire(TotalEnrollmentsByClass::class)
        ->loadTable()
        ->filterTable('academic_term_id', $term->id)
        ->filterTable('capacity_status', 'near_sold_out')
        ->callAction('saveReportView', data: [
            'name' => 'Fall capacity watch',
            'visibility' => SavedReportViewVisibility::Template->value,
        ])
        ->assertNotified('Report view saved');

    $view = SavedReportView::query()->where('name', 'Fall capacity watch')->firstOrFail();

    expect($view->user_id)->toBe($owner->id)
        ->and($view->report_key)->toBe(ReportKey::TotalEnrollmentsByClass)
        ->and($view->visibility)->toBe(SavedReportViewVisibility::Template)
        ->and($view->state['filters']['academic_term_id']['value'])->toBe($term->id)
        ->and($view->state['filters']['capacity_status']['value'])->toBe('near_sold_out');
});

it('loads an owner report template for a permitted teacher', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();
    $term = AcademicTerm::factory()->create();
    $template = SavedReportView::factory()->for($owner)->create([
        'visibility' => SavedReportViewVisibility::Template,
        'state' => [
            'filters' => [
                'academic_term_id' => ['value' => $term->id],
                'capacity_status' => ['value' => 'sold_out'],
            ],
            'search' => 'Ballet',
            'sort' => 'capacity:desc',
            'columns' => ['course_name', 'capacity'],
        ],
    ]);
    $this->actingAs($teacher);

    livewire(TotalEnrollmentsByClass::class)
        ->loadTable()
        ->callAction('loadReportView', data: ['saved_report_view_id' => $template->id])
        ->assertNotified("Loaded {$template->name}")
        ->assertSet('tableFilters.academic_term_id.value', $term->id)
        ->assertSet('tableFilters.capacity_status.value', 'sold_out')
        ->assertSet('tableSearch', 'Ballet')
        ->assertSet('tableSort', 'capacity:desc');
});

it('queues an export with the current filters and visible columns', function (): void {
    Queue::fake();
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $this->actingAs($owner);

    livewire(TotalEnrollmentsByClass::class)
        ->loadTable()
        ->filterTable('academic_term_id', $term->id)
        ->callAction('export', data: ['format' => ReportExportFormat::Xlsx->value])
        ->assertHasNoActionErrors();

    $export = ReportExport::query()->latest('id')->firstOrFail();

    expect($export->user_id)->toBe($owner->id)
        ->and($export->report_key)->toBe(ReportKey::TotalEnrollmentsByClass)
        ->and($export->format)->toBe(ReportExportFormat::Xlsx)
        ->and($export->state['filters']['academic_term_id']['value'])->toBe($term->id)
        ->and($export->state['columns'])->toContain('course_name', 'capacity');

    Queue::assertPushed(
        GenerateReportExport::class,
        fn (GenerateReportExport $job): bool => $job->reportExport->is($export),
    );
});

it('generates private csv and xlsx exports and retains them for seven days', function (ReportExportFormat $format): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create([
        'name' => ' =HYPERLINK("https://example.com")',
        'capacity' => 10,
    ]);
    Enrollment::factory()->for($course)->create();
    $export = ReportExport::factory()->for($owner)->create([
        'format' => $format,
        'state' => [
            'filters' => ['academic_term_id' => ['value' => $term->id]],
            'search' => null,
            'sort' => 'course_name:asc',
            'columns' => ['course_name', 'enrollment_count', 'capacity'],
        ],
    ]);

    (new GenerateReportExport($export))->handle(app(ReportExportService::class));
    $export->refresh();

    expect($export->status)->toBe(ReportExportStatus::Completed)
        ->and($export->total_rows)->toBe(1)
        ->and($export->completed_at?->diffInDays($export->expires_at))->toBe(7.0)
        ->and($export->path)->not->toBeNull();
    Storage::disk('local')->assertExists((string) $export->path);

    if ($format === ReportExportFormat::Csv) {
        expect(Storage::disk('local')->get((string) $export->path))
            ->toContain("' =HYPERLINK");
    }
})->with([
    'CSV' => ReportExportFormat::Csv,
    'XLSX' => ReportExportFormat::Xlsx,
]);

it('only downloads a completed unexpired export for its owner', function (): void {
    $owner = User::factory()->isOwner()->create();
    $otherOwner = User::factory()->isOwner()->create();
    $export = ReportExport::factory()->for($owner)->create([
        'status' => ReportExportStatus::Completed,
        'path' => 'report-exports/1/report.csv',
        'completed_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);
    Storage::disk('local')->put((string) $export->path, 'Course Name,Enrollments');
    $url = URL::temporarySignedRoute(
        'admin.report-exports.download',
        now()->addMinute(),
        ['reportExport' => $export],
        absolute: false,
    );

    expect($url)->toStartWith('/admin/report-exports/');

    $this->actingAsGuest()
        ->get($url)
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    $response = $this->actingAs($owner)
        ->get($url)
        ->assertOk()
        ->assertDownload($export->file_name.'.csv');

    expect($response->headers->get('content-disposition'))->toStartWith('attachment;');

    $this->actingAs($otherOwner)->get($url)->assertForbidden();

    $export->update(['expires_at' => now()->subSecond()]);
    $this->actingAs($owner)->get($url)->assertNotFound();
});

it('prunes expired export files and records', function (): void {
    $export = ReportExport::factory()->create([
        'status' => ReportExportStatus::Completed,
        'path' => 'report-exports/expired/report.csv',
        'expires_at' => now()->subMinute(),
    ]);
    Storage::disk('local')->put((string) $export->path, 'expired');

    $this->artisan('reports:prune-exports')->assertSuccessful();

    Storage::disk('local')->assertMissing((string) $export->path);
    assertDatabaseMissing('report_exports', ['id' => $export->id]);
});
