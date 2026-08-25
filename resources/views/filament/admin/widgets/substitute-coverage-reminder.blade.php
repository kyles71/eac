@php
    $coverageCount = $this->coverageCount();
@endphp

<x-filament-widgets::widget>
    @if ($coverageCount > 0)
        <div data-substitute-coverage-reminder wire:poll.15s>
            <x-filament::callout
                color="warning"
                icon="heroicon-o-exclamation-triangle"
                :heading="$this->heading()"
                :description="$this->description()"
            >
                <x-slot name="footer">
                    <x-filament::button :href="$this->eventsUrl()" tag="a" size="sm" color="warning">
                        Review Events
                    </x-filament::button>
                </x-slot>
            </x-filament::callout>
        </div>
    @endif
</x-filament-widgets::widget>
