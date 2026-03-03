<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class ShowcaseParticipation extends Model
{
    public function userForm(): MorphOne
    {
        return $this->morphOne(FormUser::class, 'responseable');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
