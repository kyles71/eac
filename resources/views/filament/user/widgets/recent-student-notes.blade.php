<x-filament-widgets::widget>
    <x-filament::section heading="Recent Student Notes" icon="heroicon-o-document-text">
        <div class="space-y-3">
            @foreach ($this->notes() as $note)
                <div
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-4 dark:border-white/10"
                    wire:key="recent-student-note-{{ $note->id }}"
                >
                    <p class="font-medium text-gray-950 dark:text-white">
                        {{ $note->data['subject'] }}
                    </p>

                    <div class="flex items-center gap-2">
                        <x-filament::button
                            size="sm"
                            wire:click="viewNote('{{ $note->id }}')"
                        >
                            View Note
                        </x-filament::button>

                        <x-filament::button
                            color="gray"
                            size="sm"
                            wire:click="dismiss('{{ $note->id }}')"
                        >
                            Dismiss
                        </x-filament::button>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
