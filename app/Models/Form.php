<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormPurpose;
use App\Enums\FormUpdateStrategy;
use App\Enums\FormVersionStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property int $id
 * @property string $name
 * @property FormPurpose $purpose
 * @property bool $updates_allowed
 * @property FormUpdateStrategy $update_strategy
 * @property-read FormVersion|null $currentVersion
 * @property-read FormVersion|null $draftVersion
 */
final class Form extends Model
{
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'purpose' => FormPurpose::class,
        'updates_allowed' => 'boolean',
        'update_strategy' => FormUpdateStrategy::class,
    ];

    public static function applyActiveConstraint(Builder $query): void
    {
        $query->whereHas('currentVersion', fn (Builder $query): Builder => $query
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>', now());
            }));
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_forms')
            ->using(CourseForm::class)
            ->withTimestamps();
    }

    public function courseForms(): HasMany
    {
        return $this->hasMany(CourseForm::class);
    }

    /** @return HasMany<FormAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(FormAssignment::class);
    }

    /** @return HasMany<FormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    /** @return HasMany<FormField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class);
    }

    /** @return HasMany<FormAnswerGroup, $this> */
    public function answerGroups(): HasMany
    {
        return $this->hasMany(FormAnswerGroup::class);
    }

    /** @return HasOne<FormVersion, $this> */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(FormVersion::class)
            ->ofMany(
                ['version' => 'max'],
                fn (Builder $query): Builder => $query->where('status', FormVersionStatus::Published),
            );
    }

    /** @return HasOne<FormVersion, $this> */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(FormVersion::class)
            ->where('status', FormVersionStatus::Draft)
            ->latestOfMany();
    }

    /**
     * @param  array<int, array<string, mixed>>  $schema
     */
    public function createDraftVersion(array $schema = [], bool $requiresSignature = false): FormVersion
    {
        $existingDraft = $this->draftVersion()->first();

        if ($existingDraft instanceof FormVersion) {
            return $existingDraft;
        }

        return $this->versions()->create([
            'version' => ((int) $this->versions()->max('version')) + 1,
            'status' => FormVersionStatus::Draft,
            'schema' => $schema,
            'requires_signature' => $requiresSignature,
        ]);
    }

    protected static function booted(): void
    {
        self::updating(function (Form $form): void {
            if ($form->isDirty('purpose') && $form->versions()->where('status', FormVersionStatus::Published)->exists()) {
                throw new LogicException('A form purpose cannot change after a version has been published.');
            }
        });

        self::deleting(function (Form $form): void {
            if ($form->assignments()->whereHas('responses')->exists()) {
                throw new LogicException('Forms with response history cannot be deleted.');
            }
        });
    }

    #[Scope]
    protected function isActive(Builder $query): void
    {
        self::applyActiveConstraint($query);
    }
}
