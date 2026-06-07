<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StudentEmailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StudentEmail extends Model
{
    /** @use HasFactory<StudentEmailFactory> */
    use HasFactory;

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
