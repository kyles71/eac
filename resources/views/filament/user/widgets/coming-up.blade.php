<x-filament-widgets::widget>
    <x-filament::section heading="My Upcoming Events" icon="heroicon-o-calendar-days">
        <x-slot name="afterHeader">
            <x-filament::button :href="$this->calendarUrl()" tag="a" size="sm">
                View calendar
            </x-filament::button>
        </x-slot>

        <div class="space-y-3">
            @forelse ($this->events() as $event)
                <div class="flex items-start gap-3 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div class="mt-1 h-3 w-3 shrink-0 rounded-full"
                        style="background-color: {{ $event['color'] ?? '#6b7280' }}"></div>
                    <div>
                        <p class="font-medium text-gray-950 dark:text-white">{{ $event['title'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            @if ($event['is_holiday'])
                                {{ $event['starts_at']->format('M j, Y') }}
                                @if (!$event['starts_at']->isSameDay($event['ends_at']))
                                    – {{ $event['ends_at']->format('M j, Y') }}
                                @endif
                                · Studio closure
                            @else
                                {{ $event['starts_at']->timezone(config('app.display_timezone'))->format('M j, Y g:i A') }}
                                @if ($event['calendar'])
                                    · {{ $event['calendar'] }}
                                @endif
                            @endif
                        </p>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Nothing is scheduled in the next 30 days.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
