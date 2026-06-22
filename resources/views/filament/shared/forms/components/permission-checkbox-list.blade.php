@php
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Str;

    $fieldWrapperView = $getFieldWrapperView();
    $extraInputAttributeBag = $getExtraInputAttributeBag();
    $groupedOptions = $getGroupedOptions();
    $isBulkToggleable = $isBulkToggleable();
    $isDisabled = $isDisabled();
    $isSearchable = $isSearchable();
    $livewireKey = $getLivewireKey();
    $options = $getOptions();
    $statePath = $getStatePath();
    $wireModelAttribute = $applyStateBindingModifiers('wire:model');
@endphp

<x-dynamic-component :component="$fieldWrapperView" :field="$field">
    <div
        x-data="{ permissionView: 'cards' }"
        x-bind:data-view="permissionView"
        class="fi-permission-checkbox-list"
    >
        <div
            x-load
            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('checkbox-list', 'filament/forms') }}"
            x-data="checkboxListFormComponent({
                        livewireId: @js($this->getId()),
                    })"
            {{ $getExtraAlpineAttributeBag()->class(['fi-fo-checkbox-list']) }}
        >
        @if (filled($description = $getDescriptionAboveSearch()))
            <p class="fi-permission-checkbox-list-description">
                {{ $description }}
            </p>
        @endif

        @if (! $isDisabled)
            @if ($isSearchable)
                <x-filament::input.wrapper
                    inline-prefix
                    :prefix-icon="Heroicon::MagnifyingGlass"
                    :prefix-icon-alias="\Filament\Forms\View\FormsIconAlias::COMPONENTS_CHECKBOX_LIST_SEARCH_FIELD"
                    class="fi-fo-checkbox-list-search-input-wrp"
                >
                    <input
                        placeholder="{{ $getSearchPrompt() }}"
                        type="search"
                        x-model.debounce.{{ $getSearchDebounce() }}="search"
                        class="fi-input fi-input-has-inline-prefix"
                    />
                </x-filament::input.wrapper>
            @endif
        @endif

        <div class="fi-permission-checkbox-list-toolbar">
            @if ((! $isDisabled) && $isBulkToggleable && count($options))
                <div
                    x-cloak
                    class="fi-fo-checkbox-list-actions"
                    wire:key="{{ $livewireKey }}.actions"
                >
                    <span
                        x-show="! areAllCheckboxesChecked"
                        x-on:click="toggleAllCheckboxes()"
                        wire:key="{{ $livewireKey }}.actions.select-all"
                    >
                        {{ $getAction('selectAll') }}
                    </span>

                    <span
                        x-show="areAllCheckboxesChecked"
                        x-on:click="toggleAllCheckboxes()"
                        wire:key="{{ $livewireKey }}.actions.deselect-all"
                    >
                        {{ $getAction('deselectAll') }}
                    </span>
                </div>
            @endif

            <x-filament::tabs contained label="Permission view">
                <x-filament::tabs.item
                    :icon="Heroicon::ListBullet"
                    alpine-active="permissionView === 'list'"
                    x-bind:aria-selected="(permissionView === 'list').toString()"
                    x-on:click="permissionView = 'list'"
                >
                    List
                </x-filament::tabs.item>

                <x-filament::tabs.item
                    :icon="Heroicon::Squares2x2"
                    alpine-active="permissionView === 'cards'"
                    x-bind:aria-selected="(permissionView === 'cards').toString()"
                    x-on:click="permissionView = 'cards'"
                >
                    Cards
                </x-filament::tabs.item>
            </x-filament::tabs>
        </div>

        <div
            @if ($isSearchable)
                x-show="visibleCheckboxListOptions.length"
            @endif
            class="fi-permission-checkbox-list-options"
        >
            @forelse ($groupedOptions as $group => $groupOptions)
                @php
                    $groupPermissions = collect(array_keys($groupOptions))
                        ->map(fn ($value): string => (string) $options[$value])
                        ->implode(' ');
                @endphp

                <section
                    class="fi-permission-checkbox-list-group"
                    data-search="{{ Str::lower($group.' '.implode(' ', $groupOptions).' '.$groupPermissions) }}"
                    @if ($isSearchable)
                        x-show="$el.dataset.search.includes(search.toLowerCase())"
                    @endif
                >
                    <h3 class="fi-permission-checkbox-list-group-heading">
                        {{ $group }}
                    </h3>

                    <div class="fi-permission-checkbox-list-group-options">
                        @foreach ($groupOptions as $value => $ability)
                            @php($permission = (string) $options[$value])

                            <div
                                wire:key="{{ $livewireKey }}.options.{{ $value }}"
                                data-search="{{ Str::lower($permission.' '.$ability.' '.$group) }}"
                                @if ($isSearchable)
                                    x-show="$el.dataset.search.includes(search.toLowerCase())"
                                @endif
                                class="fi-fo-checkbox-list-option-ctn"
                            >
                                <label class="fi-fo-checkbox-list-option">
                                    <input
                                        type="checkbox"
                                        {{
                                            $extraInputAttributeBag
                                                ->merge([
                                                    'disabled' => $isDisabled || $isOptionDisabled($value, $permission),
                                                    'value' => e($value),
                                                    'wire:loading.attr' => 'disabled',
                                                    $wireModelAttribute => $statePath,
                                                    'x-on:change' => $isBulkToggleable ? 'checkIfAllCheckboxesAreChecked()' : null,
                                                ], escape: false)
                                                ->class([
                                                    'fi-checkbox-input',
                                                    'fi-valid' => ! $errors->has($statePath),
                                                    'fi-invalid' => $errors->has($statePath),
                                                ])
                                        }}
                                    />

                                    <div class="fi-fo-checkbox-list-option-text">
                                        <span class="fi-fo-checkbox-list-option-label fi-permission-checkbox-list-list-label">
                                            {{ $permission }}
                                        </span>
                                        <span class="fi-fo-checkbox-list-option-label fi-permission-checkbox-list-card-label">
                                            {{ $ability }}
                                        </span>

                                        @if ($hasDescription($value))
                                            <p class="fi-fo-checkbox-list-option-description">
                                                {{ $getDescription($value) }}
                                            </p>
                                        @endif
                                    </div>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <div wire:key="{{ $livewireKey }}.empty"></div>
            @endforelse
        </div>

        @if ($isSearchable)
            <div
                x-cloak
                x-show="search && ! visibleCheckboxListOptions.length"
                class="fi-fo-checkbox-list-no-search-results-message"
            >
                {{ $getNoSearchResultsMessage() }}
            </div>
        @endif
        </div>
    </div>
</x-dynamic-component>
