<div class="mt-2">
    <x-filament::callout
        color="warning"
        icon="heroicon-o-exclamation-triangle"
        heading="Complete Enrollments"
        :description="'You have ' . $enrollmentCount . ' enrollment(s) that need to be assigned to a student.'"
    >
        <x-slot name="footer">
            <x-filament::button
                :href="$enrollmentsUrl"
                tag="a"
                size="sm"
            >
                Go to Enrollments
            </x-filament::button>
        </x-slot>
    </x-filament::callout>
</div>
