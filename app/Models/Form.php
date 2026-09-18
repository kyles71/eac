<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Form extends \Kyle\FilamentFormBuilder\Models\Form
{
    /** @use HasFactory<\Database\Factories\FormFactory> */
    use HasFactory;

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_forms')
            ->using(CourseForm::class)
            ->withTimestamps();
    }

    /** @return HasMany<CourseForm, $this> */
    public function courseForms(): HasMany
    {
        return $this->hasMany(CourseForm::class);
    }
}
