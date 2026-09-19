@php
    $tasks = $this->tasks();
    $taskCount = count($tasks);
@endphp

<div data-user-attention-container>
    @if ($taskCount > 0)
        <div
            data-user-attention-summary
            class="flex flex-col items-stretch gap-3 rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-warning-400/20 dark:bg-warning-400/10"
        >
            <div class="flex min-w-0 items-start gap-3 sm:items-center">
                <x-filament::icon
                    icon="heroicon-o-exclamation-triangle"
                    class="mt-0.5 h-5 w-5 shrink-0 text-warning-600 sm:mt-0 dark:text-warning-400"
                />

                <div class="min-w-0">
                    <p class="font-medium text-warning-950 dark:text-warning-50">
                        {{ $taskCount === 1 ? '1 item needs attention' : $taskCount . ' items need attention' }}
                    </p>
                    <p class="text-sm text-warning-700 dark:text-warning-300">{{ $this->summary($tasks) }}</p>
                </div>
            </div>

            <x-filament::modal id="user-attention-review" slide-over width="xl">
                <x-slot name="trigger">
                    <x-filament::button color="warning" size="sm" class="w-full sm:w-auto">
                        Review
                    </x-filament::button>
                </x-slot>

                <x-slot name="heading">
                    Items Needing Attention
                </x-slot>

                @include('filament.user.widgets.attention-tasks', [
                    'groupedTasks' => collect($tasks)->groupBy('group'),
                ])
            </x-filament::modal>
        </div>
    @endif
</div>
