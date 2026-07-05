<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Form extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'form_type' => FormTypes::class,
        'can_update' => 'boolean',
        'valid_until' => 'datetime',
    ];

    public static function applyActiveConstraint(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('valid_until')
                ->orWhere('valid_until', '>', now());
        });
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

    public function formUsers(): HasMany
    {
        return $this->hasMany(FormUser::class);
    }

    /** @param Builder<Form> $query */
    public function scopeIsActive(Builder $query): void
    {
        self::applyActiveConstraint($query);
    }
}
