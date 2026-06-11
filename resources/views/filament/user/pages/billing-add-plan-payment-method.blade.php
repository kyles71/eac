<div class="space-y-4">
    @if ($this->setupIntentClientSecret !== null && $this->paymentMethodTargetPlanId === $paymentPlanId)
        @include('filament.user.pages.billing-payment-methods')
    @endif
</div>
