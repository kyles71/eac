<div class="space-y-3">
    @forelse ($items as $item)
        @include($itemView, ['item' => $item])
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyMessage }}</p>
    @endforelse

    @if ($hasMore)
        @if ($automaticLoading)
            <div
                class="py-2 text-center text-sm text-gray-500 dark:text-gray-400"
                wire:intersect="{{ $loadMethod }}({{ $batchSize }})"
            >
                Loading additional records...
            </div>
        @else
            <x-filament::button
                color="gray"
                size="sm"
                wire:click="{{ $loadMethod }}({{ $batchSize }})"
            >
                Show additional
            </x-filament::button>
        @endif
    @endif
</div>
