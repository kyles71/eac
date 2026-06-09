<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DashboardAudience;
use App\Services\DashboardAudienceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class DashboardMessage extends Model
{
    /** @use HasFactory<\Database\Factories\DashboardMessageFactory> */
    use HasFactory;

    protected $casts = [
        'audience' => DashboardAudience::class,
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): void
    {
        $query
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        app(DashboardAudienceService::class)->applyVisibleTo($query, $user);
    }

    public function scopeAudienceOrdered(Builder $query): void
    {
        app(DashboardAudienceService::class)->applyAudienceOrder($query)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function status(): string
    {
        if ($this->published_at?->isFuture()) {
            return 'Upcoming';
        }

        if ($this->expires_at?->isPast()) {
            return 'Expired';
        }

        return 'Active';
    }
}
