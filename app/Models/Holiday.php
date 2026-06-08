<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HolidayEventScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Holiday extends Model
{
    /** @use HasFactory<\Database\Factories\HolidayFactory> */
    use HasFactory;

    public int $deletedConflictingEventsCount = 0;

    protected $attributes = [
        'scope' => HolidayEventScope::AllEvents->value,
    ];

    protected $casts = [
        'id' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'scope' => HolidayEventScope::class,
    ];
}
