<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormResponseStatus;
use App\Enums\FormVersionStatus;
use App\Forms\FormAssignmentUpdateGate;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $form_id
 * @property int $form_version_id
 * @property string $respondent_type
 * @property int $respondent_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property-read Form $form
 * @property-read FormVersion $version
 * @property-read Model $respondent
 * @property-read Model|null $subject
 * @property-read FormResponse|null $latestSubmittedResponse
 * @property-read FormResponse|null $draftResponse
 */
final class FormAssignment extends Model
{
    /** @use HasFactory<\Database\Factories\FormAssignmentFactory> */
    use HasFactory;

    public static function applyCompletedConstraint(Builder $query): void
    {
        $query->whereHas('responses', fn (Builder $query): Builder => $query
            ->where('status', FormResponseStatus::Submitted)
            ->whereColumn('form_responses.form_version_id', 'form_assignments.form_version_id'));
    }

    public static function applyPendingConstraint(Builder $query): void
    {
        $query->whereDoesntHave('responses', fn (Builder $query): Builder => $query
            ->where('status', FormResponseStatus::Submitted)
            ->whereColumn('form_responses.form_version_id', 'form_assignments.form_version_id'));
    }

    public static function applyActiveConstraint(Builder $query): void
    {
        $query->whereHas('version', fn (Builder $query): Builder => $query
            ->where('status', FormVersionStatus::Published)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>', now());
            }));
    }

    public static function applyExpiredConstraint(Builder $query): void
    {
        $query->whereHas('version', fn (Builder $query): Builder => $query
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now()));
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    public function respondent(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<FormResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class);
    }

    /** @return HasOne<FormResponse, $this> */
    public function latestSubmittedResponse(): HasOne
    {
        return $this->hasOne(FormResponse::class)
            ->where('status', FormResponseStatus::Submitted)
            ->latestOfMany('submitted_at');
    }

    /** @return HasOne<FormResponse, $this> */
    public function draftResponse(): HasOne
    {
        return $this->hasOne(FormResponse::class)
            ->where('status', FormResponseStatus::Draft)
            ->latestOfMany();
    }

    public function isCompleted(): bool
    {
        return $this->responses()
            ->where('form_version_id', $this->form_version_id)
            ->where('status', FormResponseStatus::Submitted)
            ->exists();
    }

    public function formCanBeUpdated(): bool
    {
        return $this->isCompleted()
            && $this->form->updates_allowed
            && $this->isActive()
            && app(FormAssignmentUpdateGate::class)->canUpdate($this);
    }

    public function isActive(): bool
    {
        return $this->version->isPublished()
            && ($this->version->valid_until === null || $this->version->valid_until->isFuture());
    }

    protected function casts(): array
    {
        return [
            'form_id' => 'integer',
            'form_version_id' => 'integer',
            'respondent_id' => 'integer',
            'subject_id' => 'integer',
        ];
    }

    #[Scope]
    protected function forRespondent(Builder $query, Model $respondent): void
    {
        $query
            ->where('respondent_type', $respondent->getMorphClass())
            ->where('respondent_id', $respondent->getKey());
    }

    #[Scope]
    protected function forSubject(Builder $query, Model $subject): void
    {
        $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        self::applyPendingConstraint($query);
    }

    #[Scope]
    protected function completed(Builder $query): void
    {
        self::applyCompletedConstraint($query);
    }

    #[Scope]
    protected function formIsActive(Builder $query): void
    {
        self::applyActiveConstraint($query);
    }

    #[Scope]
    protected function formIsExpired(Builder $query): void
    {
        self::applyExpiredConstraint($query);
    }
}
