<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Costume;
use App\Models\Product;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class ProductStudentAssignmentService
{
    /**
     * @param  list<int|string>  $studentIds
     */
    public function sync(Product $product, array $studentIds): void
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        if ($studentIds === []) {
            $product->assignedStudents()->sync([]);

            return;
        }

        $product->loadMissing('productable');

        $validStudents = Student::query()->whereKey($studentIds);

        if ($product->productable instanceof Costume) {
            $validStudents->whereHas('enrollments', fn (Builder $query): Builder => $query
                ->where('course_id', $product->productable->course_id));
        }

        if ($validStudents->count() !== count($studentIds)) {
            $message = $product->productable instanceof Costume
                ? 'Every assigned student must be enrolled in the costume course.'
                : 'Every assigned student must exist.';

            throw ValidationException::withMessages([
                'assignedStudents' => $message,
            ]);
        }

        $product->assignedStudents()->sync($studentIds);
    }
}
