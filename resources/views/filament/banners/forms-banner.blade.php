<div class="mt-2">
    <x-filament::callout
        color="warning"
        icon="heroicon-o-exclamation-triangle"
        heading="Forms Needed"
        :description="'You have ' . $formCount . ' form(s) that need to be completed.'"
    >
        <x-slot name="footer">
            <x-filament::button
                :href="$formsUrl"
                tag="a"
                size="sm"
            >
                Go to Forms
            </x-filament::button>
        </x-slot>
    </x-filament::callout>
</div>
