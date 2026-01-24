<?php

declare(strict_types=1);

namespace App\Models;

// use App\Actions\Common\EnrollInCourse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// use Illuminate\Support\Facades\Auth;
// use Spatie\Tags\HasTags;

final class Course extends Model
{
    /** @use HasFactory<\Database\Factories\CourseFactory> */
    use HasFactory;
    // use HasTags;

    protected $casts = [
        'id' => 'integer',
        'start_time' => 'datetime',
        'capacity' => 'integer',
        'duration' => 'integer',
        'teacher_id' => 'integer',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function activeEvents(): HasMany
    {
        return $this->events()->where('start_time', '<', now())->where('end_time', '>', now());
    }

    public function nextEvents(): HasMany
    {
        return $this->events()->where('start_time', '>', now());
    }

    public function previousEvents(): HasMany
    {
        return $this->events()->where('end_time', '<', now());
    }

    public function nextEvent(): HasOne
    {
        return $this->events()->one()->ofMany([
            'start_time' => 'max',
            'id' => 'max',
        ], function (Builder $query): void {
            $query->where('start_time', '<', now());
        });
    }

    public function previousEvent(): HasOne
    {
        return $this->events()->one()->ofMany([
            'end_time' => 'max',
            'id' => 'max',
        ], function (Builder $query): void {
            $query->where('end_time', '<', now());
        });
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // public function product(): BelongsTo
    // {
    //     return $this->belongsTo(Product::class);
    // }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function purchasers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enrollments');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'enrollments');
    }

    // public function completeItemSale(OrderItem $item): void
    // {
    //     $enroll = new EnrollInCourse();
    //     for($i = 0; $i < $item->quantity; $i++) {
    //         // $enroll->handle($item->product->sellable, Auth::id());
    //         $enroll->handle($this, Auth::id());
    //     }
    // }

    // public function saleTypeGroupAction(Order $order): void
    // {
    //     // send email detailing assigning a student to classes and completing medical waivers
    // }
}
