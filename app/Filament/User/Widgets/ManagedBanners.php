<?php

declare(strict_types=1);

namespace App\Filament\User\Widgets;

use App\Enums\ManagedBannerRenderLocation;
use App\Models\ManagedBanner;
use App\Models\User;
use App\Services\ManagedBannerScopeService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

final class ManagedBanners extends Widget
{
    public string $renderLocation;

    /** @var array<int, string> */
    public array $scopes = [];

    protected static bool $isLazy = false;

    protected string $view = 'filament.user.widgets.managed-banners';

    /**
     * @param  array<int, string>  $scopes
     */
    public function mount(ManagedBannerRenderLocation|string $renderLocation, array $scopes = []): void
    {
        $this->renderLocation = $renderLocation instanceof ManagedBannerRenderLocation
            ? $renderLocation->value
            : $renderLocation;
        $this->scopes = app(ManagedBannerScopeService::class)->matchingKeysFor(
            panelId: Filament::getCurrentPanel()->getId(),
            scopes: $scopes,
        );
    }

    /**
     * @return Collection<int, ManagedBanner>
     */
    public function banners(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection();
        }

        return ManagedBanner::query()
            ->forRenderLocation($this->renderLocation)
            ->matchingScopes($this->scopes)
            ->visibleTo($user)
            ->displayOrdered()
            ->get();
    }

    public function dismiss(int $bannerId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        ManagedBanner::query()
            ->whereKey($bannerId)
            ->visibleTo($user)
            ->first()
            ?->dismissFor($user);
    }
}
