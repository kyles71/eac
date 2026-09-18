@php
    $attention = app(\App\Support\UserAttention::class);
    $pendingForms = $this->pendingForms();
    $enrollmentCount = $this->enrollmentCount();
@endphp

<div class="space-y-2">
    @if ($enrollmentCount > 0)
        @include('filament.banners.enrollment-banner', [
            'enrollmentCount' => $enrollmentCount,
            'enrollmentsUrl' => $this->enrollmentsUrl(),
        ])
    @endif

    @php
        $waiverAssignments = $attention->assignmentsForKey($pendingForms, 'student-waiver');
    @endphp

    @if ($waiverAssignments->isNotEmpty())
        @include('filament.banners.waiver-banner', [
            'assignments' => $waiverAssignments,
            'formsUrl' => $this->formsUrl(),
        ])
    @endif

    @php
        $genericForms = $attention->genericForms($pendingForms);
    @endphp

    @if ($genericForms->isNotEmpty())
        @include('filament.banners.forms-banner', [
            'formCount' => $genericForms->count(),
            'formsUrl' => $this->formsUrl(),
        ])
    @endif
</div>
