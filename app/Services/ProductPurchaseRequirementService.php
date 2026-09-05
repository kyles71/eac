<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PurchaseRequirementStatus;
use App\Models\AcademicTerm;
use App\Models\Costume;
use App\Models\Enrollment;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @phpstan-type PurchaseRequirementTarget array{user: User, targets: list<string>}
 * @phpstan-type PurchaseRequirementRow array{
 *     user: User,
 *     targets: list<string>,
 *     required: int,
 *     purchased: int,
 *     remaining: int,
 *     status: PurchaseRequirementStatus,
 *     order_numbers: list<int>,
 *     most_recent_purchase: ?string
 * }
 */
final readonly class ProductPurchaseRequirementService
{
    public function __construct(private ProductAudienceService $audienceService) {}

    /** @return Collection<int, covariant PurchaseRequirementRow> */
    public function rowsForProduct(Product $product, ?CarbonInterface $at = null): Collection
    {
        return collect($this->rows($product, null, $at));
    }

    /** @return PurchaseRequirementRow|null */
    public function rowForUser(Product $product, User $user, ?CarbonInterface $at = null): ?array
    {
        return $this->rows($product, $user, $at)[0] ?? null;
    }

    /** @return list<PurchaseRequirementRow> */
    private function rows(Product $product, ?User $user, ?CarbonInterface $at): array
    {
        if (! $product->is_purchase_required) {
            return [];
        }

        $product->loadMissing('productable');
        $targets = $product->productable instanceof Costume
            ? $this->costumeTargetsByUser($product, $product->productable, $user)
            : $this->householdTargetsByUser($product, $user, $at);
        $purchases = $this->purchasesByUser($product, $user);

        return $targets
            ->map(function (array $target, int|string $userId) use ($purchases): array {
                $items = $purchases->get($userId);
                $purchased = $items instanceof Collection ? (int) $items->sum('quantity') : 0;
                $required = count($target['targets']);

                return [
                    'user' => $target['user'],
                    'targets' => $target['targets'],
                    'required' => $required,
                    'purchased' => $purchased,
                    'remaining' => max(0, $required - $purchased),
                    'status' => match (true) {
                        $purchased === 0 => PurchaseRequirementStatus::NotOrdered,
                        $purchased < $required => PurchaseRequirementStatus::Partial,
                        default => PurchaseRequirementStatus::Complete,
                    },
                    'order_numbers' => $items instanceof Collection
                        ? $items->pluck('order_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all()
                        : [],
                    'most_recent_purchase' => $items instanceof Collection
                        ? $items->max(fn (OrderItem $item): ?string => $item->order->created_at?->toISOString())
                        : null,
                ];
            })
            ->sortBy(fn (array $row): string => mb_strtolower($row['user']->fullName))
            ->values()
            ->all();
    }

    /** @return Collection<covariant array-key, covariant PurchaseRequirementTarget> */
    private function costumeTargetsByUser(Product $product, Costume $costume, ?User $user): Collection
    {
        $excludedStudentIds = $product->excludedStudents()->pluck('students.id');

        if ($product->assignedStudents()->exists()) {
            return $product->assignedStudents()
                ->with('user')
                ->whereNotIn('students.id', $excludedStudentIds)
                ->when($user !== null, fn (Builder $query): Builder => $query->where('students.user_id', $user->id))
                ->whereHas('enrollments', fn (Builder $query): Builder => $query->where('course_id', $costume->course_id))
                ->get()
                ->groupBy('user_id')
                ->map(fn (Collection $students): array => $this->targetForStudents($students));
        }

        $enrollments = Enrollment::query()
            ->with(['student', 'user'])
            ->where('course_id', $costume->course_id)
            ->where(function (Builder $query) use ($excludedStudentIds): void {
                $query
                    ->whereNull('student_id')
                    ->orWhereNotIn('student_id', $excludedStudentIds);
            })
            ->when($user !== null, fn (Builder $query): Builder => $query->where('user_id', $user->id))
            ->orderBy('id')
            ->get();
        $seenStudentIds = [];
        $targets = [];

        foreach ($enrollments as $enrollment) {
            if ($enrollment->student_id !== null) {
                if (isset($seenStudentIds[$enrollment->student_id])) {
                    continue;
                }

                $seenStudentIds[$enrollment->student_id] = true;
            }

            $target = $enrollment->student_id === null
                ? 'Unassigned enrollment'
                : $enrollment->student->fullName;
            $existing = $targets[$enrollment->user_id] ?? [
                'user' => $enrollment->user,
                'targets' => [],
            ];
            $existing['targets'][] = $target;
            $targets[$enrollment->user_id] = $existing;
        }

        return collect($targets);
    }

    /** @return Collection<covariant array-key, covariant PurchaseRequirementTarget> */
    private function householdTargetsByUser(Product $product, ?User $onlyUser, ?CarbonInterface $at): Collection
    {
        $users = $onlyUser instanceof User
            ? collect([$onlyUser])
            : User::query()->whereKey($this->candidateUserIds($product, $at))->get();

        return $users
            ->filter(fn (User $user): bool => $this->audienceService->includes($product, $user, $at))
            ->mapWithKeys(fn (User $user): array => [
                $user->id => [
                    'user' => $user,
                    'targets' => [$this->householdTargetLabel($product, $user, $at)],
                ],
            ]);
    }

    /** @return list<int> */
    private function candidateUserIds(Product $product, ?CarbonInterface $at): array
    {
        $hasAudience = $product->requiredCourses()->exists()
            || $product->requiredCompetitionTeams()->exists()
            || $product->assignedUsers()->exists()
            || $product->assignedStudents()->exists();

        if (! $hasAudience) {
            $comparisonDate = AcademicTerm::comparisonDate($at);

            return Enrollment::query()
                ->whereNotNull('student_id')
                ->whereHas('course.academicTerm', fn (Builder $query): Builder => $query
                    ->whereDate('starts_on', '<=', $comparisonDate)
                    ->whereDate('ends_on', '>=', $comparisonDate))
                ->pluck('user_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $userIds = $product->assignedUsers()->pluck('users.id')
            ->merge($product->assignedStudents()->pluck('students.user_id'))
            ->merge(Enrollment::query()
                ->whereIn('course_id', $product->requiredCourses()->select('courses.id'))
                ->pluck('user_id'));

        foreach ($product->requiredCompetitionTeams()->with(['staff', 'students'])->get() as $team) {
            $userIds = $userIds
                ->merge($team->staff->pluck('id'))
                ->merge($team->students->pluck('user_id'));
        }

        return $userIds
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function householdTargetLabel(Product $product, User $user, ?CarbonInterface $at): string
    {
        $excludedStudentIds = $product->excludedStudents()->pluck('students.id');
        $studentNames = $user->students()
            ->whereNotIn('students.id', $excludedStudentIds)
            ->where(function (Builder $query) use ($at, $product): void {
                $hasAudience = $product->requiredCourses()->exists()
                    || $product->requiredCompetitionTeams()->exists()
                    || $product->assignedUsers()->exists()
                    || $product->assignedStudents()->exists();

                if (! $hasAudience) {
                    $comparisonDate = AcademicTerm::comparisonDate($at);
                    $query->whereHas('enrollments.course.academicTerm', fn (Builder $query): Builder => $query
                        ->whereDate('starts_on', '<=', $comparisonDate)
                        ->whereDate('ends_on', '>=', $comparisonDate));

                    return;
                }

                $query
                    ->whereIn('students.id', $product->assignedStudents()->select('students.id'))
                    ->orWhereHas('enrollments', fn (Builder $query): Builder => $query
                        ->whereIn('course_id', $product->requiredCourses()->select('courses.id')))
                    ->orWhereHas('competitionTeams', fn (Builder $query): Builder => $query
                        ->whereIn('competition_teams.id', $product->requiredCompetitionTeams()->select('competition_teams.id')));
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (Student $student): string => $student->fullName)
            ->unique()
            ->values();

        if ($studentNames->isNotEmpty()) {
            return $studentNames->join(', ');
        }

        if ($product->assignedUsers()->whereKey($user->id)->exists()) {
            return 'Direct household assignment';
        }

        if ($product->requiredCompetitionTeams()
            ->whereHas('staff', fn (Builder $query): Builder => $query->whereKey($user->id))
            ->exists()) {
            return 'Competition Team staff assignment';
        }

        if (Enrollment::query()
            ->where('user_id', $user->id)
            ->whereNull('student_id')
            ->whereIn('course_id', $product->requiredCourses()->select('courses.id'))
            ->exists()) {
            return 'Unassigned enrollment';
        }

        return 'Household';
    }

    /** @param Collection<int, Student> $students
     * @return PurchaseRequirementTarget
     */
    private function targetForStudents(Collection $students): array
    {
        /** @var Student $firstStudent */
        $firstStudent = $students->firstOrFail();

        return [
            'user' => $firstStudent->user,
            'targets' => $students->map(fn (Student $student): string => $student->fullName)->values()->all(),
        ];
    }

    /** @return Collection<covariant array-key, covariant Collection<int, OrderItem>> */
    private function purchasesByUser(Product $product, ?User $user): Collection
    {
        return OrderItem::query()
            ->with('order')
            ->where('product_id', $product->id)
            ->whereHas('order', fn (Builder $query): Builder => $query
                ->where('status', OrderStatus::Completed->value)
                ->when($user !== null, fn (Builder $query): Builder => $query->where('user_id', $user->id)))
            ->get()
            ->toBase()
            ->groupBy(fn (OrderItem $item): int => $item->order->user_id);
    }
}
