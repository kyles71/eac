<?php

declare(strict_types=1);

namespace App\Services;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;

final class DashboardQuickLinkDestinationService
{
    public const string EXTERNAL = 'external';

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $panel = Filament::getPanel('user');

        $destinations = collect([
            ...$panel->getPages(),
            ...$panel->getResources(),
        ])
            ->filter(fn (string $destination): bool => $destination::shouldRegisterNavigation())
            ->mapWithKeys(fn (string $destination): array => [
                $destination => $destination::getNavigationLabel(),
            ])
            ->sort();

        return [
            self::EXTERNAL => 'External URL',
            ...$destinations->all(),
        ];
    }

    public function urlFor(string $destination): ?string
    {
        if ($this->isExternal($destination)) {
            return null;
        }

        if (is_subclass_of($destination, Page::class)) {
            return $destination::getUrl(panel: 'user');
        }

        if (is_subclass_of($destination, Resource::class)) {
            return $destination::getUrl(panel: 'user');
        }

        return null;
    }

    public function labelFor(string $destination): string
    {
        if ($this->isExternal($destination)) {
            return 'External URL';
        }

        if (is_subclass_of($destination, Page::class) || is_subclass_of($destination, Resource::class)) {
            return $destination::getNavigationLabel();
        }

        return 'Unavailable destination';
    }

    public function isExternal(mixed $destination): bool
    {
        return $destination === self::EXTERNAL;
    }
}
