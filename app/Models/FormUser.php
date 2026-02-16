<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FormUser extends Model
{
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

        return true;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function responseable(): MorphTo
    {
        return $this->morphTo();
    }
}
