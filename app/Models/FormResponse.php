<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormResponseStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $form_assignment_id
 * @property int $form_version_id
 * @property int|null $revision_of_id
 * @property FormResponseStatus $status
 * @property string|null $signature
 * @property \Carbon\CarbonInterface|null $date_signed
 * @property \Carbon\CarbonInterface|null $submitted_at
 * @property-read FormAssignment $assignment
 * @property-read FormVersion $version
 * @property-read FormResponse|null $revisionOf
 * @property-read Model|null $projection
 * @property-read array<string, mixed> $response_state
 * @property-read \Illuminate\Database\Eloquent\Collection<int, FormAnswer> $answers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, FormAnswerGroup> $answerGroups
 */
final class FormResponse extends Model
{
    /** @use HasFactory<\Database\Factories\FormResponseFactory> */
    use HasFactory;

    /** @return BelongsTo<FormAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(FormAssignment::class, 'form_assignment_id');
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** @return BelongsTo<FormResponse, $this> */
    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_id');
    }

    /** @return HasMany<FormAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
    }

    /** @return HasMany<FormAnswerGroup, $this> */
    public function answerGroups(): HasMany
    {
        return $this->hasMany(FormAnswerGroup::class);
    }

    public function projection(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'form_assignment_id' => 'integer',
            'form_version_id' => 'integer',
            'revision_of_id' => 'integer',
            'status' => FormResponseStatus::class,
            'date_signed' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    protected function responseState(): Attribute
    {
        return Attribute::make(
            get: fn (): array => app(\App\Forms\FormDefinition::class)->responseState($this),
        );
    }
}
