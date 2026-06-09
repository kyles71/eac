<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Models\DashboardQuickLink;
use App\Models\User;
use App\Settings\DashboardAppearanceSettings;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

final class QuickLinks extends Widget
{
    protected string $view = 'filament.shared.widgets.quick-links';

    protected int|string|array $columnSpan = 1;

    /**
     * @return Collection<int, DashboardQuickLink>
     */
    public function links(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection();
        }

        return DashboardQuickLink::query()
            ->active()
            ->visibleTo($user)
            ->audienceOrdered()
            ->get();
    }

    public function bulletImageUrl(): ?string
    {
        return app(DashboardAppearanceSettings::class)->quickLinksBulletImageUrl();
    }
}
