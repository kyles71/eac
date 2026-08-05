<div class="mt-2" data-substitute-request-banner="{{ $substituteRequest->id }}">
    <x-filament::callout
        color="warning"
        icon="heroicon-o-academic-cap"
        heading="Substitute Request"
        :description="'You have been asked to substitute for ' . $substituteRequest->event->name . ' on ' . $substituteRequest->event->start_time->timezone(config('app.display_timezone'))->format('M j, Y \a\t g:i A') . '.'"
    >
        <x-slot name="footer">
            <div class="flex flex-wrap gap-2">
                <x-filament::button :href="$reviewUrl" tag="a" size="sm" color="gray">
                    Review Details
                </x-filament::button>
                <x-filament::button
                    wire:click="acceptSubstituteRequest({{ $substituteRequest->id }})"
                    wire:confirm="Accept this substitute request?"
                    size="sm"
                    color="success"
                >
                    Accept
                </x-filament::button>
                <x-filament::button
                    wire:click="declineSubstituteRequest({{ $substituteRequest->id }})"
                    wire:confirm="Decline this substitute request?"
                    size="sm"
                    color="danger"
                >
                    Decline
                </x-filament::button>
            </div>
        </x-slot>
    </x-filament::callout>
</div>
