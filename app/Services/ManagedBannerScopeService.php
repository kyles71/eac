<?php

declare(strict_types=1);

namespace App\Services;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Support\Str;

final class ManagedBannerScopeService
{
    private const string KEY_SEPARATOR = '|';

    /**
     * @return array<string, array<string, string>>
     */
    public function options(): array
    {
        $options = [];

        foreach (['admin', 'user'] as $panelId) {
            $panel = Filament::getPanel($panelId);
            $panelLabel = Str::headline($panelId);

            $pages = collect($panel->getPages())
                ->filter(fn (string $page): bool => is_subclass_of($page, Page::class))
                ->reject(fn (string $page): bool => str_contains($page, '\\Auth\\'))
                ->mapWithKeys(fn (string $page): array => [
                    $this->keyFor($panelId, $page) => $page::getNavigationLabel(),
                ])
                ->sort()
                ->all();

            $resources = collect($panel->getResources())
                ->filter(fn (string $resource): bool => is_subclass_of($resource, Resource::class))
                ->mapWithKeys(fn (string $resource): array => [
                    $this->keyFor($panelId, $resource) => $resource::getNavigationLabel().' (all pages)',
                ])
                ->sort()
                ->all();

            $resourcePages = $this->resourcePageOptions($panelId, $panel);

            $options = [
                ...$options,
                ...array_filter([
                    "{$panelLabel} pages" => $pages,
                    "{$panelLabel} resources" => $resources,
                    "{$panelLabel} resource pages" => $resourcePages,
                ]),
            ];
        }

        return $options;
    }

    public function keyFor(string $panelId, string $scope): string
    {
        return $panelId.self::KEY_SEPARATOR.$scope;
    }

    /**
     * @param  array<int, string>  $scopes
     * @return list<string>
     */
    public function matchingKeysFor(string $panelId, array $scopes): array
    {
        return collect($scopes)
            ->filter(fn (mixed $scope): bool => is_string($scope))
            ->flatMap(fn (string $scope): array => [
                $scope,
                $this->keyFor($panelId, $scope),
            ])
            ->unique()
            ->values()
            ->all();
    }

    public function labelFor(string $scope): string
    {
        foreach ($this->options() as $options) {
            if (array_key_exists($scope, $options)) {
                return $options[$scope];
            }
        }

        [$panelId, $class] = $this->parseKey($scope);

        return filled($panelId)
            ? Str::headline($panelId).' '.Str::headline(class_basename($class))
            : Str::headline(class_basename($class));
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

    /**
     * @return array<string, string>
     */
    private function resourcePageOptions(string $panelId, Panel $panel): array
    {
        $resourcePages = [];

        foreach ($panel->getResources() as $resource) {
            if (! is_subclass_of($resource, Resource::class)) {
                continue;
            }

            foreach ($resource::getPages() as $page) {
                $pageClass = $page->getPage();

                $resourcePages[$this->keyFor($panelId, $pageClass)] = $resource::getNavigationLabel().': '.Str::headline(class_basename($pageClass));
            }
        }

        asort($resourcePages);

        return $resourcePages;
    }
}
