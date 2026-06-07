<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormTypes;
use Database\Factories\FormUserFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class FormUser extends Model
{
    /** @use HasFactory<FormUserFactory> */
    use HasFactory;

    protected $casts = [
        'form_id' => 'integer',
        'user_id' => 'integer',
        'student_id' => 'integer',
        'date_signed' => 'date',
    ];

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function formCanBeUpdated(): bool
    {
        if (! ($this->date_signed && $this->signature)) {
            return false;
        }

        if (! $this->form->can_update) {
            return false;
        }

        if ($this->form->valid_until && $this->form->valid_until->isPast()) {
            return false;
        }

        if ($this->form->form_type === FormTypes::StudentWaiver) {
            return $this->student?->latestValidCompletedMedicalWaiver()?->is($this) ?? false;
        }

        return true;
    }

    public function isCompleted(): bool
    {
        return filled($this->signature) && $this->date_signed !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function responseable(): MorphTo
    {
        return $this->morphTo();
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->whereNull('signature')
                ->orWhereNull('date_signed');
        });
    }

    #[Scope]
    protected function completed(Builder $query): void
    {
        $query
            ->whereNotNull('signature')
            ->whereNotNull('date_signed');
    }

    #[Scope]
    protected function formIsActive(Builder $query): void
    {
        $query->join('forms', function ($join) {
            $join->on('form_users.form_id', '=', 'forms.id')
                ->where(function ($q) {
                    $q->whereNull('forms.valid_until')
                        ->orWhere('forms.valid_until', '>', now());
                });
        });
    }

    #[Scope]
    protected function formIsExpired(Builder $query): void
    {
        $query->join('forms', function ($join) {
            $join->on('form_users.form_id', '=', 'forms.id')
                ->where('forms.valid_until', '<=', now());
        });
    }
}
