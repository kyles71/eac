<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DashboardAudience;
use App\Services\DashboardAudienceService;
use App\Services\DashboardQuickLinkDestinationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class DashboardQuickLink extends Model
{
    /** @use HasFactory<\Database\Factories\DashboardQuickLinkFactory> */
    use HasFactory;

    protected $casts = [
        'audience' => DashboardAudience::class,
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        app(DashboardAudienceService::class)->applyVisibleTo($query, $user);
    }

    public function scopeAudienceOrdered(Builder $query): void
    {
        app(DashboardAudienceService::class)->applyAudienceOrder($query)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function resolvedUrl(): string
    {
        $destinations = app(DashboardQuickLinkDestinationService::class);

        return $destinations->isExternal($this->destination)
            ? (string) $this->external_url
            : ($destinations->urlFor($this->destination) ?? '#');
    }

    public function opensInNewTab(): bool
    {
        return app(DashboardQuickLinkDestinationService::class)->isExternal($this->destination);
    }
}
