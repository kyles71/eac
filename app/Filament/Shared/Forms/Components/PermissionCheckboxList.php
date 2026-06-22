<?php

declare(strict_types=1);

namespace App\Filament\Shared\Forms\Components;

use Filament\Forms\Components\CheckboxList;
use Illuminate\Support\Str;

final class PermissionCheckboxList extends CheckboxList
{
    protected string $view = 'filament.shared.forms.components.permission-checkbox-list';

    protected ?string $descriptionAboveSearch = null;

    public function descriptionAboveSearch(?string $description): static
    {
        $this->descriptionAboveSearch = $description;

        return $this;
    }

    public function getDescriptionAboveSearch(): ?string
    {
        return $this->descriptionAboveSearch;
    }

    /**
     * @return array<string, array<int|string, string>>
     */
    public function getGroupedOptions(): array
    {
        $groups = [];

        foreach ($this->getOptions() as $value => $permission) {
            $permission = (string) $permission;
            $hasGroup = str_contains($permission, ':');
            $group = $hasGroup ? Str::headline(Str::afterLast($permission, ':')) : 'Other';
            $ability = $hasGroup ? Str::headline(Str::beforeLast($permission, ':')) : Str::headline($permission);

            $groups[$group][$value] = $ability;
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return $groups;
    }
}
