<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DashboardAudience;
use App\Enums\ManagedBannerRenderLocation;
use App\Enums\ManagedBannerTone;
use App\Services\DashboardAudienceService;
use App\Services\ManagedBannerDestinationService;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class ManagedBanner extends Model
{
    /** @use HasFactory<\Database\Factories\ManagedBannerFactory> */
    use HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'target_scopes' => 'array',
        'audiences' => 'array',
        'tone' => ManagedBannerTone::class,
        'render_location' => ManagedBannerRenderLocation::class,
        'cta_new_tab' => 'boolean',
        'is_dismissible' => 'boolean',
    ];

    /**
     * @return HasMany<ManagedBannerDismissal, $this>
     */
    public function dismissals(): HasMany
    {
        return $this->hasMany(ManagedBannerDismissal::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function scopeForRenderLocation(Builder $query, ManagedBannerRenderLocation|string $renderLocation): void
    {
        $renderLocation = $renderLocation instanceof ManagedBannerRenderLocation
            ? $renderLocation->value
            : $renderLocation;

        $query->where('render_location', $renderLocation);
    }

    /**
     * @param  array<int, string>  $scopes
     */
    public function scopeMatchingScopes(Builder $query, array $scopes): void
    {
        $scopes = array_values(array_filter($scopes, is_string(...)));

        $query->where(function (Builder $query) use ($scopes): void {
            $query
                ->whereNull('target_scopes')
                ->orWhereJsonLength('target_scopes', 0);

            foreach ($scopes as $scope) {
                $query->orWhereJsonContains('target_scopes', $scope);
            }
        });
    }

    public function scopeMatchingAudiences(Builder $query, User $user): void
    {
        $audiences = collect(app(DashboardAudienceService::class)->audiencesFor($user))
            ->map(fn (DashboardAudience $audience): string => $audience->value)
            ->values()
            ->all();

        $query->where(function (Builder $query) use ($audiences): void {
            foreach ($audiences as $audience) {
                $query->orWhereJsonContains('audiences', $audience);
            }
        });
    }

    public function scopeNotDismissedBy(Builder $query, User $user): void
    {
        $query->whereDoesntHave(
            'dismissals',
            fn (Builder $query): Builder => $query->where('user_id', $user->id),
        );
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query
            ->active()
            ->matchingAudiences($user)
            ->notDismissedBy($user);
    }

    public function scopeDisplayOrdered(Builder $query): void
    {
        $query
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->orderByDesc('id');
    }

    public function dismissFor(User $user): ?ManagedBannerDismissal
    {
        if (! $this->is_dismissible) {
            return null;
        }

        /** @var ManagedBannerDismissal $dismissal */
        $dismissal = $this->dismissals()->firstOrCreate(
            ['user_id' => $user->id],
            ['dismissed_at' => now()],
        );

        return $dismissal;
    }

    public function isDismissedBy(User $user): bool
    {
        if (! $this->relationLoaded('dismissals')) {
            return $this->dismissals()->where('user_id', $user->id)->exists();
        }

        /** @var Collection<int, ManagedBannerDismissal> $dismissals */
        $dismissals = $this->dismissals;

        return $dismissals->contains('user_id', $user->id);
    }

    public function hasCallToAction(): bool
    {
        return filled($this->cta_label) && filled($this->resolvedCtaUrl());
    }

    public function resolvedCtaUrl(): ?string
    {
        if (filled($this->cta_destination) && ! app(ManagedBannerDestinationService::class)->isExternal($this->cta_destination)) {
            return app(ManagedBannerDestinationService::class)->urlFor($this->cta_destination);
        }

        return filled($this->cta_url) ? $this->cta_url : null;
    }

    public function resolvedIcon(): BackedEnum|string|null
    {
        if (filled($this->icon)) {
            return $this->icon;
        }

        return $this->tone->defaultIcon();
    }

    public function status(): string
    {
        if (! $this->is_active) {
            return 'Inactive';
        }

        if ($this->published_at?->isFuture()) {
            return 'Upcoming';
        }

        if ($this->expires_at?->isPast()) {
            return 'Expired';
        }

        return 'Active';
    }

    /**
     * @return list<string>
     */
    public function audienceLabels(): array
    {
        return collect(Arr::wrap($this->audiences))
            ->map(fn (string $audience): ?string => DashboardAudience::tryFrom($audience)?->getLabel())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function targetScopeLabels(): array
    {
        return collect(Arr::wrap($this->target_scopes))
            ->map(fn (string $scope): string => Str::afterLast($scope, '\\'))
            ->values()
            ->all();
    }
}
