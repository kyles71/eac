<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\Reports\ReportDataset;
use App\Enums\ReportKey;
use App\Models\AcademicTerm;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Services\StudentProfileService;
use App\Settings\ReportingSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Spatie\Tags\Tag;

final readonly class EnrollmentReportService
{
    public function __construct(
        private ReportingSettings $settings,
        private StudentProfileService $studentProfiles,
    ) {}

    /**
     * @return array{
     *     enrollment_count: int,
     *     total_capacity: int,
     *     capacity_percentage: float|null,
     *     target_remaining: int|null,
     *     stretch_remaining: int|null,
     *     sold_out_count: int,
     *     not_running_count: int,
     *     near_sold_out_count: int,
     *     dancer_count: int,
     *     average_classes_per_dancer: float,
     *     unassigned_count: int
     * }
     */
    public function dashboard(?AcademicTerm $term, User $user): array
    {
        if (! $term instanceof AcademicTerm) {
            return [
                'enrollment_count' => 0,
                'total_capacity' => 0,
                'capacity_percentage' => null,
                'target_remaining' => null,
                'stretch_remaining' => null,
                'sold_out_count' => 0,
                'not_running_count' => 0,
                'near_sold_out_count' => 0,
                'dancer_count' => 0,
                'average_classes_per_dancer' => 0.0,
                'unassigned_count' => 0,
            ];
        }

        $courses = $this->courseQuery($term, $user)
            ->with(['tags'])
            ->withCount('enrollments')
            ->get()
            ->reject(fn (Course $course): bool => $this->isExcludedFromDashboard($course));
        $courseIds = $courses->modelKeys();
        $enrollmentCount = (int) $courses->sum('enrollments_count');
        $totalCapacity = (int) $courses->sum('capacity');
        $dancerCount = $courseIds === []
            ? 0
            : Enrollment::query()
                ->whereIn('course_id', $courseIds)
                ->whereNotNull('student_id')
                ->distinct('student_id')
                ->count('student_id');
        $assignedEnrollmentCount = $courseIds === []
            ? 0
            : Enrollment::query()
                ->whereIn('course_id', $courseIds)
                ->whereNotNull('student_id')
                ->count();
        $unassignedCount = $courseIds === []
            ? 0
            : Enrollment::query()
                ->whereIn('course_id', $courseIds)
                ->whereNull('student_id')
                ->count();

        return [
            'enrollment_count' => $enrollmentCount,
            'total_capacity' => $totalCapacity,
            'capacity_percentage' => $this->percentage($enrollmentCount, $totalCapacity),
            'target_remaining' => $term->target_enrollments === null
                ? null
                : max(0, $term->target_enrollments - $enrollmentCount),
            'stretch_remaining' => $term->stretch_goal_enrollments === null
                ? null
                : max(0, $term->stretch_goal_enrollments - $enrollmentCount),
            'sold_out_count' => $courses->filter(
                fn (Course $course): bool => (int) $course->enrollments_count >= $course->capacity,
            )->count(),
            'not_running_count' => $courses->filter(
                fn (Course $course): bool => (int) $course->enrollments_count <= $this->settings->not_running_maximum_enrollments,
            )->count(),
            'near_sold_out_count' => $courses->filter(function (Course $course): bool {
                $remaining = $course->capacity - (int) $course->enrollments_count;

                return $remaining >= 1
                    && $remaining <= $this->settings->near_sold_out_maximum_remaining;
            })->count(),
            'dancer_count' => $dancerCount,
            'average_classes_per_dancer' => $dancerCount === 0
                ? 0.0
                : round($assignedEnrollmentCount / $dancerCount, 2),
            'unassigned_count' => $unassignedCount,
        ];
    }

    /** @return list<array{name: string, tag_slugs: list<string>}> */
    public function configuredCapacityMetrics(): array
    {
        $validSlugs = array_fill_keys(array_keys($this->courseTagOptions()), true);
        $capacityMetrics = [];

        foreach ($this->settings->capacity_metrics as $capacityMetric) {
            $name = $capacityMetric['name'] ?? null;
            $configuredTagSlugs = $capacityMetric['tag_slugs'] ?? [];
            $tagSlugs = [];

            foreach (is_array($configuredTagSlugs) ? $configuredTagSlugs : [] as $tagSlug) {
                if (is_string($tagSlug) && array_key_exists($tagSlug, $validSlugs)) {
                    $tagSlugs[$tagSlug] = true;
                }
            }

            if (! is_string($name) || blank($name) || $tagSlugs === []) {
                continue;
            }

            $capacityMetrics[] = [
                'name' => mb_trim($name),
                'tag_slugs' => array_keys($tagSlugs),
            ];
        }

        return $capacityMetrics;
    }

    /**
     * @param  list<string>  $tagSlugs
     * @return list<array{slug: string, label: string, enrollment_count: int, capacity: int, percentage: float|null}>
     */
    public function capacityByTags(?AcademicTerm $term, User $user, array $tagSlugs): array
    {
        if (! $term instanceof AcademicTerm) {
            return $this->tagCapacities(collect(), $tagSlugs);
        }

        $courses = $this->courseQuery($term, $user)
            ->with('tags')
            ->withCount('enrollments')
            ->get()
            ->reject(fn (Course $course): bool => $this->isExcludedFromDashboard($course));

        return $this->tagCapacities($courses, $tagSlugs);
    }

    /** @param array<string, mixed> $filters */
    public function dataset(ReportKey $report, User $user, array $filters): ReportDataset
    {
        return match ($report) {
            ReportKey::EnrollmentsByTerm => $this->enrollmentsByTerm(
                $this->academicTerm($filters),
                $user,
                $filters,
            ),
            ReportKey::TotalEnrollmentsByClass => $this->totalEnrollmentsByClass(
                $this->academicTerm($filters),
                $user,
                $filters,
            ),
            ReportKey::CompetitionEnrollments => $this->competitionEnrollments(
                $this->academicTerm($filters),
                $this->competitionSeason($filters),
                $user,
            ),
            ReportKey::TermEmailList => $this->termEmailList(
                $this->academicTerm($filters),
                $user,
            ),
            ReportKey::CompetitionEmailList => $this->competitionEmailList(
                $this->competitionSeason($filters),
                $filters,
            ),
            default => throw new InvalidArgumentException("{$report->label()} is not an enrollment report."),
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
    public function competitionSeasonOptions(): array
    {
        return CompetitionSeason::query()
            ->orderByDesc('starts_on')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, array<int, string>> */
    public function courseOptions(User $user): array
    {
        return Course::query()
            ->when(
                $user->hasCourseRestrictedAdminAccess(),
                fn (Builder $query): Builder => $query->whereHas(
                    'teachers',
                    fn (Builder $query): Builder => $query->whereKey($user->id),
                ),
            )
            ->with('academicTerm.academicYear')
            ->orderByDesc('academic_term_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Course $course): string => $course->academicTerm->display_name)
            ->map(fn (Collection $courses): array => $courses
                ->mapWithKeys(fn (Course $course): array => [$course->id => $course->name])
                ->all())
            ->all();
    }

    /** @return array<string, array<int, string>> */
    public function competitionTeamOptions(): array
    {
        return CompetitionTeam::query()
            ->with('season')
            ->orderByDesc('competition_season_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CompetitionTeam $team): string => $team->season->name)
            ->map(fn (Collection $teams): array => $teams
                ->mapWithKeys(fn (CompetitionTeam $team): array => [$team->id => $team->name])
                ->all())
            ->all();
    }

    /** @return array<string, string> */
    public function courseTagOptions(): array
    {
        return Tag::query()
            ->withType(Course::GENERAL_TAG_TYPE)
            ->orderBy('order_column')
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [(string) $tag->slug => (string) $tag->name])
            ->all();
    }

    /** @param array<string, mixed> $filters */
    private function enrollmentsByTerm(
        ?AcademicTerm $term,
        User $user,
        array $filters,
    ): ReportDataset {
        $baseHeaders = [
            'dancer_name' => 'Dancer Name',
            'user_name' => 'User Name',
            'medical_waiver' => 'Medical Waiver',
            'media_release' => 'Media Release',
        ];

        if (! $term instanceof AcademicTerm) {
            return new ReportDataset($baseHeaders, []);
        }

        $courses = $this->courseQuery($term, $user)
            ->withCount('enrollments')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $headers = [
            ...$baseHeaders,
            ...$courses->mapWithKeys(fn (Course $course): array => [
                "course_{$course->id}" => $course->name,
            ])->all(),
        ];

        if ($courses->isEmpty()) {
            return new ReportDataset($headers, []);
        }

        $enrollments = Enrollment::query()
            ->whereIn('course_id', $courses->modelKeys())
            ->with([
                'course:id,name',
                'student:id,user_id,first_name,last_name',
                'student.user:id,first_name,last_name,email',
                'user:id,first_name,last_name,email',
            ])
            ->get();
        $courseId = $this->integerFilterValue($filters, 'course_id');
        $rows = [];

        foreach ($enrollments->whereNotNull('student_id')->groupBy('student_id') as $studentEnrollments) {
            if ($courseId !== null && ! $studentEnrollments->pluck('course_id')->contains($courseId)) {
                continue;
            }

            /** @var Enrollment $firstEnrollment */
            $firstEnrollment = $studentEnrollments->first();
            $student = $firstEnrollment->student;

            if (! $student instanceof Student) {
                continue;
            }

            $row = array_fill_keys(array_keys($headers), '');
            $row['_key'] = "student_{$student->id}";
            $row['dancer_name'] = $student->fullName;
            $row['user_name'] = $studentEnrollments
                ->pluck('user.fullName')
                ->filter()
                ->unique()
                ->sort()
                ->implode(', ');
            $row['medical_waiver'] = $student->medicalWaiverStatus()->getLabel();
            $row['media_release'] = $this->mediaReleaseStatus($student);

            foreach ($studentEnrollments as $enrollment) {
                $row["course_{$enrollment->course_id}"] = 'X';
            }

            $rows[] = $row;
        }

        foreach ($enrollments->whereNull('student_id') as $enrollment) {
            if ($courseId !== null) {
                continue;
            }

            $row = array_fill_keys(array_keys($headers), '');
            $row['_key'] = "unassigned_{$enrollment->id}";
            $row['dancer_name'] = 'Unassigned';
            $row['user_name'] = $enrollment->user->fullName;
            $row['medical_waiver'] = 'Pending';
            $row['media_release'] = 'Pending';
            $row["course_{$enrollment->course_id}"] = 'X';
            $rows[] = $row;
        }

        $totalRow = array_fill_keys(array_keys($headers), '');
        $totalRow['_key'] = 'footer_total';
        $totalRow['dancer_name'] = 'Total Enrolled';
        $capacityRow = array_fill_keys(array_keys($headers), '');
        $capacityRow['_key'] = 'footer_capacity';
        $capacityRow['dancer_name'] = 'Total Capacity';

        foreach ($courses as $course) {
            $totalRow["course_{$course->id}"] = collect($rows)
                ->where("course_{$course->id}", 'X')
                ->count();
            $capacityRow["course_{$course->id}"] = $course->capacity;
        }

        return new ReportDataset($headers, $rows, [$totalRow, $capacityRow]);
    }

    /** @param array<string, mixed> $filters */
    private function totalEnrollmentsByClass(
        ?AcademicTerm $term,
        User $user,
        array $filters,
    ): ReportDataset {
        $headers = [
            'course_name' => 'Course Name',
            'enrollment_count' => 'Enrollments',
            'capacity' => 'Capacity',
            'available' => 'Available',
            'utilization' => 'Utilization',
        ];

        if (! $term instanceof AcademicTerm) {
            return new ReportDataset($headers, []);
        }

        $tag = $this->filterValue($filters, 'course_tag');
        $courses = $this->courseQuery($term, $user)
            ->when(filled($tag), fn (Builder $query): Builder => $query->withAnyTags(
                (string) $tag,
                Course::GENERAL_TAG_TYPE,
            ))
            ->withCount('enrollments')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $capacityStatus = $this->filterValue($filters, 'capacity_status');

        $rows = $courses
            ->filter(fn (Course $course): bool => $this->matchesCapacityStatus($course, $capacityStatus))
            ->map(function (Course $course): array {
                $enrollmentCount = (int) $course->enrollments_count;

                return [
                    '_key' => "course_{$course->id}",
                    'course_name' => $course->name,
                    'enrollment_count' => $enrollmentCount,
                    'capacity' => $course->capacity,
                    'available' => max(0, $course->capacity - $enrollmentCount),
                    'utilization' => $this->percentage($enrollmentCount, $course->capacity) === null
                        ? '—'
                        : number_format((float) $this->percentage($enrollmentCount, $course->capacity), 1).'%',
                ];
            })
            ->values()
            ->all();

        return new ReportDataset($headers, $rows);
    }

    private function competitionEnrollments(
        ?AcademicTerm $term,
        ?CompetitionSeason $season,
        User $user,
    ): ReportDataset {
        $headers = [
            'dancer_name' => 'Dancer Name',
            'competition_team' => 'Competition Team',
            'course_name' => 'Enrolled Course',
        ];

        if (! $term instanceof AcademicTerm || ! $season instanceof CompetitionSeason) {
            return new ReportDataset($headers, []);
        }

        $courseIds = $this->courseQuery($term, $user)->pluck('id');
        $enrollments = Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('student_id')
            ->whereHas(
                'student.competitionTeams',
                fn (Builder $query): Builder => $query->where('competition_season_id', $season->id),
            )
            ->with([
                'course:id,name',
                'student:id,first_name,last_name',
                'student.competitionTeams' => fn ($query) => $query
                    ->where('competition_season_id', $season->id)
                    ->orderBy('name'),
            ])
            ->get();
        $rows = [];

        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;

            if (! $student instanceof Student) {
                continue;
            }

            foreach ($student->competitionTeams as $team) {
                $rows[] = [
                    '_key' => "enrollment_{$enrollment->id}_team_{$team->id}",
                    'dancer_name' => $student->fullName,
                    'competition_team' => $team->name,
                    'course_name' => $enrollment->course->name,
                ];
            }
        }

        return new ReportDataset($headers, $rows);
    }

    private function termEmailList(?AcademicTerm $term, User $user): ReportDataset
    {
        $headers = ['email' => 'Email', 'sources' => 'Sources'];

        if (! $term instanceof AcademicTerm) {
            return new ReportDataset($headers, []);
        }

        $courseIds = $this->courseQuery($term, $user)->pluck('id');
        $enrollments = Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->with([
                'student:id,user_id,first_name,last_name',
                'student.additionalEmails:id,student_id,email',
                'student.user:id,email',
                'user:id,email',
            ])
            ->get();
        $emails = [];

        foreach ($enrollments as $enrollment) {
            $this->addEmail($emails, $enrollment->user->email, 'Enrollment user account');

            if (! $enrollment->student instanceof Student) {
                continue;
            }

            $studentName = $enrollment->student->fullName;
            $this->addEmail($emails, $enrollment->student->user?->email, "{$studentName} account");

            foreach ($enrollment->student->additionalEmails as $studentEmail) {
                $this->addEmail($emails, $studentEmail->email, "{$studentName} additional email");
            }
        }

        return new ReportDataset($headers, $this->emailRows($emails));
    }

    /** @param array<string, mixed> $filters */
    private function competitionEmailList(?CompetitionSeason $season, array $filters): ReportDataset
    {
        $headers = [
            'email' => 'Email',
            'competition_team' => 'Competition Team',
            'sources' => 'Sources',
        ];

        if (! $season instanceof CompetitionSeason) {
            return new ReportDataset($headers, []);
        }

        $teamId = $this->integerFilterValue($filters, 'competition_team_id');
        $students = Student::query()
            ->whereHas(
                'competitionTeams',
                fn (Builder $query): Builder => $query
                    ->where('competition_season_id', $season->id)
                    ->when($teamId !== null, fn (Builder $query): Builder => $query->whereKey($teamId)),
            )
            ->with([
                'user:id,email',
                'additionalEmails:id,student_id,email',
                'competitionTeams' => function (Relation $relation) use ($season, $teamId): void {
                    $relation->getQuery()
                        ->where('competition_season_id', $season->id)
                        ->when(
                            $teamId !== null,
                            fn (Builder $query): Builder => $query->whereKey($teamId),
                        )
                        ->orderBy('name');
                },
            ])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
        $emails = [];

        foreach ($students as $student) {
            $teamNames = $student->competitionTeams->pluck('name')->all();
            $this->addEmail($emails, $student->user?->email, "{$student->fullName} account", $teamNames);

            foreach ($student->additionalEmails as $studentEmail) {
                $this->addEmail(
                    $emails,
                    $studentEmail->email,
                    "{$student->fullName} additional email",
                    $teamNames,
                );
            }
        }

        return new ReportDataset($headers, $this->emailRows($emails, includeCompetitionTeams: true));
    }

    /** @return Builder<Course> */
    private function courseQuery(AcademicTerm $term, User $user): Builder
    {
        return Course::query()
            ->where('academic_term_id', $term->id)
            ->when(
                $user->hasCourseRestrictedAdminAccess(),
                fn (Builder $query): Builder => $query->whereHas(
                    'teachers',
                    fn (Builder $query): Builder => $query->whereKey($user->id),
                ),
            );
    }

    /**
     * @param  Collection<int, Course>  $courses
     * @param  list<string>  $tagSlugs
     * @return list<array{slug: string, label: string, enrollment_count: int, capacity: int, percentage: float|null}>
     */
    private function tagCapacities(Collection $courses, array $tagSlugs): array
    {
        $tagOptions = $this->courseTagOptions();

        return collect($tagSlugs)
            ->map(function (string $slug) use ($courses, $tagOptions): ?array {
                if (! array_key_exists($slug, $tagOptions)) {
                    return null;
                }

                $tagCourses = $courses->filter(fn (Course $course): bool => $course->tags
                    ->contains(fn (Model $courseTag): bool => $courseTag instanceof Tag
                        && $courseTag->type === Course::GENERAL_TAG_TYPE
                        && $courseTag->matchesString($slug)));
                $enrollmentCount = (int) $tagCourses->sum('enrollments_count');
                $capacity = (int) $tagCourses->sum('capacity');

                return [
                    'slug' => $slug,
                    'label' => $tagOptions[$slug],
                    'enrollment_count' => $enrollmentCount,
                    'capacity' => $capacity,
                    'percentage' => $this->percentage($enrollmentCount, $capacity),
                ];
            })
            ->filter()
            ->values()
            ->all();
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

    private function matchesCapacityStatus(Course $course, mixed $status): bool
    {
        $enrollments = (int) $course->enrollments_count;
        $remaining = $course->capacity - $enrollments;

        return match ($status) {
            'sold_out' => $remaining <= 0,
            'not_running' => $enrollments <= $this->settings->not_running_maximum_enrollments,
            'near_sold_out' => $remaining >= 1
                && $remaining <= $this->settings->near_sold_out_maximum_remaining,
            default => true,
        };
    }

    private function isExcludedFromDashboard(Course $course): bool
    {
        return collect($this->settings->excluded_course_tag_slugs)
            ->contains(fn (string $slug): bool => $course->tags
                ->contains(fn (Model $tag): bool => $tag instanceof Tag
                    && $tag->type === Course::GENERAL_TAG_TYPE
                    && $tag->matchesString($slug)));
    }

    /** @param array<string, mixed> $filters */
    private function academicTerm(array $filters): ?AcademicTerm
    {
        $id = filter_var($this->filterValue($filters, 'academic_term_id'), FILTER_VALIDATE_INT);

        if ($id === false) {
            return $this->currentTerm();
        }

        return AcademicTerm::query()->find($id);
    }

    /** @param array<string, mixed> $filters */
    private function competitionSeason(array $filters): ?CompetitionSeason
    {
        $id = filter_var($this->filterValue($filters, 'competition_season_id'), FILTER_VALIDATE_INT);

        return $id === false
            ? $this->currentCompetitionSeason()
            : CompetitionSeason::query()->find($id);
    }

    /** @param array<string, mixed> $filters */
    private function filterValue(array $filters, string $name): mixed
    {
        $value = $filters[$name] ?? null;

        return is_array($value) ? ($value['value'] ?? null) : $value;
    }

    /** @param array<string, mixed> $filters */
    private function integerFilterValue(array $filters, string $name): ?int
    {
        $value = filter_var($this->filterValue($filters, $name), FILTER_VALIDATE_INT);

        return $value === false ? null : $value;
    }

    private function percentage(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round(($numerator / $denominator) * 100, 1);
    }

    /**
     * @param  array<string, array{email: string, sources: array<string, true>, competition_teams: array<string, true>}>  $emails
     * @param  list<string>  $competitionTeams
     */
    private function addEmail(
        array &$emails,
        mixed $email,
        string $source,
        array $competitionTeams = [],
    ): void {
        if (! is_string($email)) {
            return;
        }

        $email = mb_trim($email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $key = mb_strtolower($email);
        $emails[$key] ??= ['email' => $email, 'sources' => [], 'competition_teams' => []];
        $emails[$key]['sources'][$source] = true;

        foreach ($competitionTeams as $competitionTeam) {
            $emails[$key]['competition_teams'][$competitionTeam] = true;
        }
    }

    /**
     * @param  array<string, array{email: string, sources: array<string, true>, competition_teams: array<string, true>}>  $emails
     * @return list<array<string, string>>
     */
    private function emailRows(array $emails, bool $includeCompetitionTeams = false): array
    {
        ksort($emails, SORT_NATURAL | SORT_FLAG_CASE);

        return collect($emails)
            ->map(function (array $email, string $key) use ($includeCompetitionTeams): array {
                $row = [
                    '_key' => 'email_'.sha1($key),
                    'email' => $email['email'],
                    'sources' => implode(', ', array_keys($email['sources'])),
                ];

                if ($includeCompetitionTeams) {
                    $row = [
                        '_key' => $row['_key'],
                        'email' => $row['email'],
                        'competition_team' => implode(', ', array_keys($email['competition_teams'])),
                        'sources' => $row['sources'],
                    ];
                }

                return $row;
            })
            ->values()
            ->all();
    }
}
