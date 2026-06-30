<?php

declare(strict_types=1);

namespace App\Services;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Str;

final class ManagedBannerDestinationService
{
    public const string EXTERNAL = 'external';

    private const string KEY_SEPARATOR = '|';

    /**
     * @return array<string, array<string, string>>
     */
    public function options(): array
    {
        $options = ['External' => [self::EXTERNAL => 'External URL']];

        foreach (['admin', 'user'] as $panelId) {
            $panel = Filament::getPanel($panelId);
            $panelLabel = Str::headline($panelId);

            $destinations = collect([
                ...$panel->getPages(),
                ...$panel->getResources(),
            ])
                ->filter(fn (string $destination): bool => is_subclass_of($destination, Page::class) || is_subclass_of($destination, Resource::class))
                ->reject(fn (string $destination): bool => str_contains($destination, '\\Auth\\'))
                ->mapWithKeys(fn (string $destination): array => [
                    $this->keyFor($panelId, $destination) => $destination::getNavigationLabel(),
                ])
                ->sort()
                ->all();

            if ($destinations !== []) {
                $options["{$panelLabel} destinations"] = $destinations;
            }
        }

        return $options;
    }

    public function keyFor(string $panelId, string $destination): string
    {
        return $panelId.self::KEY_SEPARATOR.$destination;
    }

    public function urlFor(?string $destination): ?string
    {
        if (blank($destination) || $this->isExternal($destination)) {
            return null;
        }

        [$panelId, $class] = $this->parseKey($destination);

        if ($panelId === null) {
            return null;
        }

        if (is_subclass_of($class, Page::class)) {
            return $class::getUrl(panel: $panelId);
        }

        if (is_subclass_of($class, Resource::class)) {
            return $class::getUrl(panel: $panelId);
        }

        return null;
    }

    public function labelFor(?string $destination): string
    {
        if ($this->isExternal($destination)) {
            return 'External URL';
        }

        if (! is_string($destination)) {
            return 'No destination';
        }

        foreach ($this->options() as $options) {
            if (array_key_exists($destination, $options)) {
                return $options[$destination];
            }
        }

        [$panelId, $class] = $this->parseKey($destination);

        return filled($panelId)
            ? Str::headline($panelId).' '.Str::headline(class_basename($class))
            : Str::headline(class_basename($class));
    }

    public function isExternal(mixed $destination): bool
    {
        return blank($destination) || $destination === self::EXTERNAL;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function parseKey(string $key): array
    {
        if (! str_contains($key, self::KEY_SEPARATOR)) {
            return [null, $key];
        }

        $parts = explode(self::KEY_SEPARATOR, $key, 2);

        return [$parts[0], $parts[1]];
    }
}
