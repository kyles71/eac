<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CostumeOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Costume;
use App\Models\Enrollment;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * @phpstan-type CostumeTarget array{user: User, targets: list<string>}
 * @phpstan-type CostumeRequirementRow array{
 *     user: User,
 *     targets: list<string>,
 *     required: int,
 *     purchased: int,
 *     remaining: int,
 *     status: CostumeOrderStatus,
 *     order_numbers: list<int>,
 *     most_recent_purchase: ?string
 * }
 */
final readonly class CostumePurchaseRequirementService
{
    /** @return Collection<int, covariant CostumeRequirementRow> */
    public function rowsForCostume(Costume $costume): Collection
    {
        $costume->loadMissing('product');

        if (! $costume->product instanceof Product) {
            return collect($this->emptyRows());
        }

        return collect($this->rows($costume->product));
    }

    /** @return Collection<int, covariant CostumeRequirementRow> */
    public function rowsForProduct(Product $product): Collection
    {
        return collect($this->rows($product));
    }

    /** @return CostumeRequirementRow|null */
    public function rowForUser(Product $product, User $user): ?array
    {
        return $this->rows($product, $user)[0] ?? null;
    }

    /** @return list<CostumeRequirementRow> */
    private function rows(Product $product, ?User $user = null): array
    {
        $product->loadMissing('productable');

        if (! $product->productable instanceof Costume) {
            throw new InvalidArgumentException('Costume requirements are only available for Costume Products.');
        }

        $targets = $this->targetsByUser($product, $product->productable, $user);
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
                        $purchased === 0 => CostumeOrderStatus::NotOrdered,
                        $purchased < $required => CostumeOrderStatus::Partial,
                        default => CostumeOrderStatus::Complete,
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

    /** @return Collection<covariant array-key, covariant CostumeTarget> */
    private function targetsByUser(Product $product, Costume $costume, ?User $user): Collection
    {
        if ($product->assignedStudents()->exists()) {
            return $product->assignedStudents()
                ->with('user')
                ->when($user !== null, fn (Builder $query): Builder => $query->where('students.user_id', $user->id))
                ->whereHas('enrollments', fn (Builder $query): Builder => $query->where('course_id', $costume->course_id))
                ->get()
                ->groupBy('user_id')
                ->map(fn (Collection $students): array => $this->targetForStudents($students));
        }

        $enrollments = Enrollment::query()
            ->with(['student', 'user'])
            ->where('course_id', $costume->course_id)
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

    /**
     * @param  Collection<int, Student>  $students
     * @return CostumeTarget
     */
    private function targetForStudents(Collection $students): array
    {
        $firstStudent = $students->first();

        if (! $firstStudent instanceof Student) {
            throw new InvalidArgumentException('A costume student group cannot be empty.');
        }

        $user = $firstStudent->user;

        if (! $user instanceof User) {
            throw new InvalidArgumentException('An assigned costume student must belong to a household.');
        }

        return [
            'user' => $user,
            'targets' => $students
                ->map(fn (Student $student): string => $student->fullName)
                ->values()
                ->all(),
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

    /** @return list<CostumeRequirementRow> */
    private function emptyRows(): array
    {
        return [];
    }
}
