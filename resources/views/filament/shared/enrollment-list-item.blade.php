<div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <p class="font-medium text-gray-950 dark:text-white">{{ $item['course'] }}</p>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if (filled($item['semester']))
                    {{ $item['semester'] }}
                @endif

                @if (filled($item['teacher']))
                    @if (filled($item['semester']))
                        <span aria-hidden="true"> · </span>
                    @endif

                    {!! $item['teacher'] !!}
                @endif
            </p>
        </div>

        <x-filament::badge :color="match ($item['status']) {
            'Active' => 'success',
            'Future' => 'info',
            default => 'gray',
        }">
            {{ $item['status'] }}
        </x-filament::badge>
    </div>

    @if ($item['starts_at'])
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            {{ $item['status'] === 'Past' ? 'Last meeting' : 'Next meeting' }}: {{ $item['starts_at'] }}
        </p>
    @endif
</div>
