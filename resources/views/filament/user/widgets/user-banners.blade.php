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

    @foreach (\App\Enums\FormTypes::cases() as $formType)
        @php
            $bannerView = $formType->getBannerView();
            $assignments = $attention->assignmentsForFormType($pendingForms, $formType);
        @endphp

        @if ($bannerView !== null && $assignments->isNotEmpty())
            @include($bannerView, [
                'assignments' => $assignments,
                'formsUrl' => $this->formsUrl(),
            ])
        @endif
    @endforeach

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
