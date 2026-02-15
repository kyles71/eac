<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CourseForm extends Pivot
{
    public $incrementing = true;

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
