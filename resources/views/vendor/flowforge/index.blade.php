@php
    use Filament\Support\Facades\FilamentAsset;

    $canManageStages = $this->canManageStages();
    $collapseStorageKey = 'flowforge.board.'.$this->boardWorkspaceId().'.collapsed-stages';
@endphp

@props(['columns', 'config'])

<div
    class="flex h-full w-full flex-col"
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('flowforge', package: 'relaticle/flowforge') }}"
    x-data="flowforge({
        state: {
            columns: @js($columns),
            titleField: '{{ $config['recordTitleAttribute'] }}',
            columnField: '{{ $config['columnIdentifierAttribute'] }}',
            cardLabel: '{{ $config['cardLabel'] }}',
            pluralCardLabel: '{{ $config['pluralCardLabel'] }}',
        }
    })"
>
    @unless($config['headerToolbar'] ?? false)
        @include('flowforge::components.filters')
    @endunless

    <div
        class="min-h-0 flex-1 overflow-hidden"
        x-data="{
            collapsedStageIds: [],
            collapseStorageKey: @js($collapseStorageKey),
            stageSortable: null,
            sortableRetry: null,

            init() {
                this.loadCollapsedStages()

                @if($canManageStages)
                    this.$nextTick(() => this.initializeStageSorting())
                @endif
            },

            destroy() {
                window.clearTimeout(this.sortableRetry)
                this.stageSortable?.destroy()
            },

            initializeStageSorting() {
                if (! window.Sortable) {
                    this.sortableRetry = window.setTimeout(() => this.initializeStageSorting(), 50)

                    return
                }

                this.stageSortable?.destroy()
                this.stageSortable = window.Sortable.create(this.$refs.stageList, {
                    animation: 200,
                    draggable: '[data-stage-sortable-item]',
                    handle: '[data-stage-sortable-handle]',
                    ghostClass: 'fi-sortable-ghost',
                    onEnd: () => this.$wire.reorderStages(this.stageIds()),
                })
            },

            stageIds() {
                return Array.from(this.$refs.stageList.querySelectorAll(':scope > [data-stage-sortable-item]'))
                    .map((element) => element.dataset.stageId)
            },

            loadCollapsedStages() {
                try {
                    const stored = JSON.parse(window.localStorage.getItem(this.collapseStorageKey) ?? '[]')
                    this.collapsedStageIds = Array.isArray(stored) ? stored.map(String) : []
                } catch {
                    this.collapsedStageIds = []
                }
            },

            isStageCollapsed(stageId) {
                return this.collapsedStageIds.includes(String(stageId))
            },

            toggleStage(stageId) {
                const normalizedId = String(stageId)

                this.collapsedStageIds = this.isStageCollapsed(normalizedId)
                    ? this.collapsedStageIds.filter((id) => id !== normalizedId)
                    : [...this.collapsedStageIds, normalizedId]

                window.localStorage.setItem(this.collapseStorageKey, JSON.stringify(this.collapsedStageIds))
            },
        }"
    >
        <div
            class="flex h-full flex-row gap-x-5 overflow-x-auto overflow-y-hidden"
            x-ref="stageList"
        >
            @foreach($columns as $columnId => $column)
                <x-flowforge::column
                    :columnId="$columnId"
                    :column="$column"
                    :config="$config"
                    wire:key="column-{{ $columnId }}"
                />
            @endforeach

            @if($canManageStages)
                <div class="flex w-[300px] min-w-[300px] flex-shrink-0 items-start">
                    <div class="w-full rounded-xl border border-dashed border-gray-300 p-3 dark:border-gray-600">
                        {{ $this->addStageAction }}
                    </div>
                </div>
            @endif
        </div>
    </div>

    <x-filament-actions::modals />
</div>
