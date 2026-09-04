@props(['columnId', 'column', 'config'])

@php
    use Filament\Actions\ActionGroup;
    use Relaticle\Flowforge\Support\ColorResolver;

    $resolvedColor = ColorResolver::resolve($column['color']);
    $isSemantic = ColorResolver::isSemantic($resolvedColor);
    $colorShades = $isSemantic ? null : $resolvedColor;
    $count = $column['total'] ?? (isset($column['items']) ? count($column['items']) : 0);
    $processedActions = $this->getBoardColumnActions($columnId);
    $processedActionGroup = count($processedActions) > 1
        ? ActionGroup::make($processedActions)
            ->extraDropdownAttributes(['x-on:close-stage-menus.window' => 'close()'])
        : null;
@endphp

<div
    class="flowforge-column flex max-h-full flex-shrink-0 flex-col overflow-hidden rounded-xl border border-gray-200 shadow-sm transition-[width,min-width] duration-200 dark:border-gray-700 dark:shadow-md"
    data-stage-sortable-item
    data-stage-id="{{ $columnId }}"
    x-data="{ stageId: $el.dataset.stageId }"
    x-bind:class="isStageCollapsed(stageId) ? 'w-14 min-w-14' : 'w-[300px] min-w-[300px]'"
>
    <div
        class="flowforge-column-header flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700"
        x-show="! isStageCollapsed(stageId)"
    >
        <div
            @class([
                'flex min-w-0 items-center',
                'cursor-grab active:cursor-grabbing' => $this->canManageStages(),
            ])
            @if($this->canManageStages())
                data-stage-sortable-handle
            @endif
        >
            @if($column['icon'] ?? null)
                <x-filament::icon :icon="$column['icon']" class="me-2 h-4 w-4 text-gray-500 dark:text-gray-400" />
            @endif

            <h3 class="truncate text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ $column['label'] }}
            </h3>
        </div>

        <div class="ms-2 flex flex-shrink-0 items-center gap-1.5">
            @if($isSemantic)
                <x-filament::badge tag="div" :color="$resolvedColor">
                    {{ $count }}
                </x-filament::badge>
            @elseif($colorShades)
                <div
                    @style([Filament\Support\get_color_css_variables($resolvedColor, shades: [50, 300, 600, 700])])
                    class="items-center rounded-md border border-custom-700/30 bg-custom-50 px-2 py-0.5 text-xs font-semibold text-custom-700 dark:border-custom-300/30 dark:bg-custom-600/20 dark:text-custom-300"
                >
                    {{ $count }}
                </div>
            @else
                <div class="items-center rounded-md border border-gray-700/30 bg-gray-50 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:border-gray-300/30 dark:bg-gray-600/20 dark:text-gray-300">
                    {{ $count }}
                </div>
            @endif

            <x-filament::icon-button
                icon="heroicon-o-arrows-pointing-in"
                color="gray"
                size="sm"
                label="Collapse {{ $column['label'] }}"
                x-on:click.stop="$dispatch('close-stage-menus'); toggleStage(stageId)"
            />

            @if(count($processedActions) > 0)
                <div>
                    @if(count($processedActions) === 1)
                        {{ $processedActions[0] }}
                    @else
                        {{ $processedActionGroup }}
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div
        class="flex h-full flex-col items-center gap-3 px-2 py-3"
        x-cloak
        x-show="isStageCollapsed(stageId)"
    >
        <x-filament::icon-button
            icon="heroicon-o-arrows-pointing-out"
            color="gray"
            size="sm"
            label="Expand {{ $column['label'] }}"
            x-on:click.stop="toggleStage(stageId)"
        />

        <div
            @class([
                'flex min-h-0 flex-1 items-center justify-center',
                'cursor-grab active:cursor-grabbing' => $this->canManageStages(),
            ])
            @if($this->canManageStages())
                data-stage-sortable-handle
            @endif
        >
            <h3
                class="text-sm font-medium text-gray-700 dark:text-gray-200"
                style="writing-mode: vertical-rl;"
            >
                {{ $column['label'] }}
            </h3>
        </div>

        <x-filament::badge tag="div" :color="$isSemantic ? $resolvedColor : 'gray'">
            {{ $count }}
        </x-filament::badge>
    </div>

    <div
        data-column-id="{{ $columnId }}"
        @if($this->getBoard()->getPositionIdentifierAttribute() && $this->canMoveCards())
            x-sortable
            x-sortable-group="cards"
            @end.stop="handleSortableEnd($event)"
        @endif
        @if(isset($column['total']) && $column['total'] > count($column['items']))
            @scroll.throttle.100ms="handleColumnScroll($event, '{{ $columnId }}')"
        @endif
        class="flowforge-column-content kanban-cards flex-1 overflow-x-hidden overflow-y-auto overscroll-y-contain p-3"
        style="max-height: calc(100vh - 13rem);"
        x-show="! isStageCollapsed(stageId)"
    >
        @if(isset($column['items']) && count($column['items']) > 0)
            @foreach($column['items'] as $record)
                <x-flowforge::card
                    :record="$record"
                    :config="$config"
                    :columnId="$columnId"
                    wire:key="card-{{ $record['id'] }}"
                />
            @endforeach

            <div class="py-3 text-center">
                @if(isset($column['total']) && $column['total'] > count($column['items']))
                    <div
                        class="w-full"
                        x-intersect.margin.300px="handleSmoothScroll('{{ $columnId }}')"
                    >
                        <div
                            class="flex items-center justify-center gap-2 text-xs text-primary-600 dark:text-primary-400"
                            x-show="isLoadingColumn('{{ $columnId }}')"
                            x-transition
                        >
                            {{ __('flowforge::flowforge.loading_more_cards') }}
                        </div>
                    </div>
                @endif
            </div>
        @else
            <x-flowforge::empty-column
                :columnId="$columnId"
                :pluralCardLabel="$config['pluralCardLabel']"
            />
        @endif
    </div>
</div>
