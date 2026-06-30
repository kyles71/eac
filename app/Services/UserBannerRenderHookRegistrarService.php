<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ManagedBannerRenderLocation;
use App\Filament\User\Widgets\ManagedBanners;
use App\Filament\User\Widgets\UserBanners;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

final class UserBannerRenderHookRegistrarService
{
    public function registerManagedBanners(): void
    {
        foreach (ManagedBannerRenderLocation::cases() as $renderLocation) {
            $this->registerManagedBannersForLocation($renderLocation);
        }
    }

    public function registerSystemAttentionBanners(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
            fn (): string => Blade::render('@livewire('.UserBanners::class.'::class)'),
        );
    }

    private function registerManagedBannersForLocation(ManagedBannerRenderLocation $renderLocation): void
    {
        FilamentView::registerRenderHook(
            $renderLocation->value,
            fn (array $scopes = []): string => Blade::render(
                '@livewire('.ManagedBanners::class.'::class, ["renderLocation" => $renderLocation, "scopes" => $scopes], key($key))',
                [
                    'key' => 'managed-banners-'.str_replace([':', '.'], '-', $renderLocation->value),
                    'renderLocation' => $renderLocation->value,
                    'scopes' => $scopes,
                ],
            ),
        );
    }
}
