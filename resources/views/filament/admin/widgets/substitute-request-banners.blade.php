@php
    $substituteRequests = $this->pendingSubstituteRequests();
@endphp

<div class="space-y-2" wire:poll.15s>
    @foreach ($substituteRequests as $substituteRequest)
        @include('filament.banners.substitute-request-banner', [
            'substituteRequest' => $substituteRequest,
            'reviewUrl' => $this->substituteRequestUrl($substituteRequest),
        ])
    @endforeach
</div>
