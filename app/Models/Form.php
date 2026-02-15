<?php

namespace App\Models;

use App\Enums\FormTypes;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Form extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'form_type' => FormTypes::class,
        'can_update' => 'boolean',
        'valid_until' => 'datetime',
    ];

    #[Scope]
    protected function isActive(Builder $query): void
    {
        $query->where(function ($q) {
            $q->whereNull('valid_until')
                ->orWhere('valid_until', '>', now());
        });
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_forms');
    }

    public function courseForms(): HasMany
    {
        return $this->hasMany(CourseForm::class);
    }

    public function formUsers(): HasMany
    {
        return $this->hasMany(FormUser::class);
    }
}
