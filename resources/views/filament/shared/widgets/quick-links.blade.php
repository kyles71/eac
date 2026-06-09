<x-filament-widgets::widget>
    <x-filament::section heading="Quick Links" icon="heroicon-o-link">
        @php($bulletImageUrl = $this->bulletImageUrl())

        <ul class="max-h-72 overflow-y-auto pr-2">
            @forelse ($this->links() as $link)
                <li class="flex items-start gap-3 py-1.5">
                    @if ($bulletImageUrl)
                        <img src="{{ $bulletImageUrl }}" alt="" class="mt-0.5 h-5 w-5 shrink-0 object-contain">
                    @else
                        <span aria-hidden="true" class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary-500"></span>
                    @endif

                    <a href="{{ $link->resolvedUrl() }}"
                        @if ($link->opensInNewTab()) target="_blank"
                            rel="noopener noreferrer" @endif
                        class="flex min-w-0 flex-1 items-center justify-between gap-3 text-sm font-medium text-primary-600 transition hover:underline dark:text-primary-400">
                        <span>{{ $link->label }}</span>
                        <x-filament::icon :icon="$link->opensInNewTab()
                            ? 'heroicon-o-arrow-top-right-on-square'
                            : 'heroicon-o-chevron-right'" class="h-4 w-4 shrink-0" />
                    </a>
                </li>
            @empty
                <li class="text-sm text-gray-500 dark:text-gray-400">No quick links are available.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
