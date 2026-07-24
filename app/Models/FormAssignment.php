<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property \Carbon\CarbonInterface $created_at
 * @property \Carbon\CarbonInterface $updated_at
 * @property bool $is_manually_assigned
 * @property int|null $manually_assigned_by_id
 * @property \Carbon\CarbonInterface|null $manually_assigned_at
 */
final class FormAssignment extends \Kyle\FilamentFormBuilder\Models\FormAssignment
{
    /** @use HasFactory<\Database\Factories\FormAssignmentFactory> */
    use HasFactory;

    public static function applyAccessibleConstraint(Builder $query, User $user): void
    {
        $studentType = (new Student)->getMorphClass();

        $query->where(function (Builder $query) use ($studentType, $user): void {
            $query
                ->where(function (Builder $query) use ($studentType, $user): void {
                    $query
                        ->where('subject_type', $studentType)
                        ->whereIn('subject_id', Student::query()->where('user_id', $user->id)->select('id'));
                })
                ->orWhere(function (Builder $query) use ($studentType, $user): void {
                    $query
                        ->where(fn (Builder $query): Builder => $query
                            ->whereNull('subject_type')
                            ->orWhere('subject_type', '!=', $studentType))
                        ->where('respondent_type', $user->getMorphClass())
                        ->where('respondent_id', $user->id);
                });
        });
    }

    /** @return BelongsTo<User, $this> */
    public function manuallyAssignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manually_assigned_by_id');
    }

    public function isAccessibleBy(User $user): bool
    {
        if ($this->subject_type === (new Student)->getMorphClass()) {
            return Student::query()
                ->whereKey($this->subject_id)
                ->where('user_id', $user->id)
                ->exists();
        }

        return $this->respondent_type === $user->getMorphClass()
            && (string) $this->respondent_id === (string) $user->getKey();
    }

    public function scopeAccessibleBy(Builder $query, User $user): void
    {
        self::applyAccessibleConstraint($query, $user);
    }

    protected static function boot(): void
    {
        parent::boot();

        self::saving(function (FormAssignment $assignment): void {
            if ($assignment->subject_type !== (new Student)->getMorphClass() || $assignment->subject_id === null) {
                return;
            }

            $student = Student::query()->find($assignment->subject_id);

            if ($student === null || $student->user_id === null) {
                throw new LogicException('Student form assignments require a student linked to a user.');
            }

            if ($assignment->respondent_type !== (new User)->getMorphClass()
                || (int) $assignment->respondent_id !== $student->user_id) {
                throw new LogicException('A student form assignment respondent must be the student\'s current linked user.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'is_manually_assigned' => 'boolean',
            'manually_assigned_by_id' => 'integer',
            'manually_assigned_at' => 'datetime',
        ];
    }
}
