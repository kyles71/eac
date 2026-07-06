<div class="mt-2">
    @php
        $names = $assignments
            ->map(fn ($assignment) => $assignment->subject?->first_name)
            ->filter()
            ->unique()
            ->join(', ', ' and ');
    @endphp

    <x-filament::callout
        color="warning"
        icon="heroicon-o-exclamation-triangle"
        heading="Waivers Needed"
        :description="'The following students need waivers signed: ' . $names"
    >
        <x-slot name="footer">
            <x-filament::button
                :href="$formsUrl"
                tag="a"
                size="sm"
            >
                Go to Waivers
            </x-filament::button>
        </x-slot>
    </x-filament::callout>
</div>
