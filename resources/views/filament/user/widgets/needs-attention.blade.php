<x-filament-widgets::widget>
    <x-filament::section heading="Needs Attention" icon="heroicon-o-exclamation-circle">
        <div class="space-y-3">
            @forelse ($this->tasks() as $task)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div>
                        <p class="font-medium text-gray-950 dark:text-white">{{ $task['title'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $task['description'] }}</p>
                    </div>

                    <x-filament::button :color="$task['color']" :href="$task['url']" tag="a" size="sm">
                        {{ $task['action'] }}
                    </x-filament::button>
                </div>
            @empty
                <div class="rounded-lg bg-success-50 p-4 text-sm text-success-700 dark:bg-success-500/10 dark:text-success-400">
                    You are all caught up. There is nothing that needs your attention right now.
                </div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
