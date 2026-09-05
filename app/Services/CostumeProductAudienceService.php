<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Costume;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class CostumeProductAudienceService
{
    public function includes(Product $product, User $user): bool
    {
        $product->loadMissing('productable');

        if (! $product->productable instanceof Costume) {
            return true;
        }

        $costume = $product->productable;

        if ($product->assignedStudents()->exists()) {
            return $product->assignedStudents()
                ->where('students.user_id', $user->id)
                ->whereHas('enrollments', fn (Builder $query): Builder => $query
                    ->where('course_id', $costume->course_id))
                ->exists();
        }

        return $costume->course->enrollments()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function applyToQuery(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query
                ->whereNull('productable_type')
                ->orWhere('productable_type', '!=', Costume::class)
                ->orWhere(function (Builder $query) use ($user): void {
                    $query
                        ->where('productable_type', Costume::class)
                        ->where(function (Builder $query) use ($user): void {
                            $query
                                ->where(function (Builder $query) use ($user): void {
                                    $query
                                        ->whereDoesntHave('assignedStudents')
                                        ->whereExists($this->courseEnrollmentQuery($user));
                                })
                                ->orWhereExists($this->assignedStudentEnrollmentQuery($user));
                        });
                });
        });
    }

    private function courseEnrollmentQuery(User $user): QueryBuilder
    {
        return DB::table('costumes')
            ->join('enrollments', 'enrollments.course_id', '=', 'costumes.course_id')
            ->selectRaw('1')
            ->whereColumn('costumes.id', 'products.productable_id')
            ->where('enrollments.user_id', $user->id);
    }

    private function assignedStudentEnrollmentQuery(User $user): QueryBuilder
    {
        return DB::table('product_student_assignment')
            ->join('students', 'students.id', '=', 'product_student_assignment.student_id')
            ->join('enrollments', 'enrollments.student_id', '=', 'students.id')
            ->join('costumes', function (JoinClause $join): void {
                $join
                    ->on('costumes.id', '=', 'products.productable_id')
                    ->on('costumes.course_id', '=', 'enrollments.course_id');
            })
            ->selectRaw('1')
            ->whereColumn('product_student_assignment.product_id', 'products.id')
            ->where('students.user_id', $user->id);
    }
}
