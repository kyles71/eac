<x-filament-widgets::widget>
    <x-filament::section heading="Messages From EAC" icon="heroicon-o-chat-bubble-left-right">
        <x-slot name="afterHeader">
            {{ $this->viewAllAction() }}
        </x-slot>

        @php($bulletImageUrl = $this->bulletImageUrl())

        <ul class="max-h-72 overflow-y-auto pr-2">
            @forelse ($this->messages() as $message)
                <li class="flex items-start gap-3 py-1.5 text-sm text-gray-700 dark:text-gray-200">
                    @if ($bulletImageUrl)
                        <img src="{{ $bulletImageUrl }}" alt="" class="mt-0.5 h-5 w-5 shrink-0 object-contain">
                    @else
                        <span aria-hidden="true" class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary-500"></span>
                    @endif

                    <span>{{ $message->message }}</span>
                </li>
            @empty
                <li class="text-sm text-gray-500 dark:text-gray-400">No current messages.</li>
            @endforelse
        </ul>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
