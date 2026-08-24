<?php

declare(strict_types=1);

namespace App\Actions\CourseHolds;

use App\Actions\Mail\SendCourseHoldEmail;
use App\Models\Course;
use App\Models\CourseHold;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class CreateCourseHold
{
    public function __construct(private SendCourseHoldEmail $sendEmail) {}

    /**
     * @param  list<array{course_id: int, quantity: int, student_ids?: list<int|null>}>  $lines
     */
    public function handle(
        User $user,
        CarbonInterface $expiresAt,
        array $lines,
        ?User $createdBy = null,
        ?string $notes = null,
    ): CourseHold {
        if ($expiresAt->lte(now())) {
            throw new InvalidArgumentException('The hold expiration must be in the future.');
        }

        if ($lines === []) {
            throw new InvalidArgumentException('Add at least one class to the hold.');
        }

        $hold = DB::transaction(function () use ($user, $expiresAt, $lines, $createdBy, $notes): CourseHold {
            /** @var CourseHold $hold */
            $hold = CourseHold::query()->create([
                'user_id' => $user->id,
                'created_by_user_id' => $createdBy?->id,
                'expires_at' => $expiresAt,
                'notes' => filled($notes) ? $notes : null,
            ]);

            foreach ($this->normalizedLines($lines) as $line) {
                /** @var Course|null $course */
                $course = Course::query()
                    ->with('product.productable')
                    ->lockForUpdate()
                    ->find($line['course_id']);

                if ($course === null) {
                    throw new InvalidArgumentException('One of the selected classes no longer exists.');
                }

                $product = $this->purchasableProduct($course, $user);

                if ($line['quantity'] > $course->getAvailableCapacity()) {
                    throw new InvalidArgumentException("Not enough unreserved seats remain in \"{$course->name}\".");
                }

                $studentIds = $this->validatedStudentIds($user, $line['student_ids'] ?? []);

                for ($index = 0; $index < $line['quantity']; $index++) {
                    $hold->seats()->create([
                        'course_id' => $course->id,
                        'student_id' => $studentIds[$index] ?? null,
                        'locked_unit_price' => $product->price,
                    ]);
                }
            }

            return $hold->load(['user', 'seats.course.product', 'seats.student']);
        });

        $this->sendEmail->handle($hold, 'course-hold-created');

        return $hold;
    }

    /**
     * @param  list<array{course_id: int, quantity: int, student_ids?: list<int|null>}>  $lines
     * @return list<array{course_id: int, quantity: int, student_ids: list<int|null>}>
     */
    private function normalizedLines(array $lines): array
    {
        return collect($lines)
            ->map(function (array $line): array {
                $courseId = (int) ($line['course_id'] ?? 0);
                $quantity = (int) ($line['quantity'] ?? 0);

                if ($courseId < 1 || $quantity < 1) {
                    throw new InvalidArgumentException('Each held class must have a valid class and quantity.');
                }

                return [
                    'course_id' => $courseId,
                    'quantity' => $quantity,
                    'student_ids' => array_values($line['student_ids'] ?? []),
                ];
            })
            ->groupBy('course_id')
            ->map(fn ($group): array => [
                'course_id' => (int) $group->first()['course_id'],
                'quantity' => (int) $group->sum('quantity'),
                'student_ids' => $group->flatMap(fn (array $line): array => $line['student_ids'])->values()->all(),
            ])
            ->sortBy('course_id')
            ->values()
            ->all();
    }

    private function purchasableProduct(Course $course, User $user): Product
    {
        $product = $course->product;

        if (! $product instanceof Product || ! $product->canBePurchasedBy($user) || ! is_int($product->price) || $product->price <= 0) {
            throw new InvalidArgumentException("\"{$course->name}\" does not have a product this family can purchase.");
        }

        return $product;
    }

    /**
     * @param  list<int|null>  $studentIds
     * @return list<int|null>
     */
    private function validatedStudentIds(User $user, array $studentIds): array
    {
        $ids = collect($studentIds)->filter()->map(fn (mixed $id): int => (int) $id)->values();

        if ($ids->isEmpty()) {
            return array_values($studentIds);
        }

        $validIds = Student::query()
            ->where('user_id', $user->id)
            ->whereKey($ids)
            ->pluck('id');

        if ($validIds->count() !== $ids->unique()->count()) {
            throw new InvalidArgumentException('Each assigned student must belong to the selected family.');
        }

        return array_map(fn (mixed $id): ?int => filled($id) ? (int) $id : null, $studentIds);
    }
}
