<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MediaDisks;
use Database\Factories\StaffNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class StaffNote extends Model implements HasMedia
{
    /** @use HasFactory<StaffNoteFactory> */
    use HasFactory, InteractsWithMedia;

    protected $casts = [
        'id' => 'integer',
        'student_id' => 'integer',
        'author_id' => 'integer',
    ];

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents')
            ->useDisk(MediaDisks::private());
    }
}
