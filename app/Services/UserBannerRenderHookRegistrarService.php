<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ManagedBannerRenderLocation;
use App\Filament\User\Widgets\ManagedBanners;
use App\Filament\User\Widgets\UserBanners;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

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
                    'key' => $this->managedBannersKey($renderLocation, $scopes),
                    'renderLocation' => $renderLocation->value,
                    'scopes' => $scopes,
                ],
            ),
        );
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private function managedBannersKey(ManagedBannerRenderLocation $renderLocation, array $scopes): string
    {
        $segments = [
            $renderLocation->value,
            ...array_values(array_filter($scopes, is_string(...))),
            $this->requestRenderToken(),
        ];

        $key = preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '-',
            str_replace(['\\', ':', '.', '|'], '-', implode('|', $segments)),
        );

        return 'managed-banners-'.mb_trim((string) $key, '-');
    }

    /**
     * Keep hook children fresh when a parent Livewire page re-renders.
     */
    private function requestRenderToken(): string
    {
        $attribute = 'managed-banners-render-token';
        $token = request()->attributes->get($attribute);

        if (is_string($token)) {
            return $token;
        }

        $token = (string) Str::uuid();

        request()->attributes->set($attribute, $token);

        return $token;
    }
}
