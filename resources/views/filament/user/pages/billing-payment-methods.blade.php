@php
    $isForPaymentPlan = $this->paymentMethodTargetPlanId !== null;
    $returnUrl = \App\Filament\User\Pages\Billing::getUrl([
        'tab' => $isForPaymentPlan ? 'payment-plans' : 'payment-methods',
    ]);
@endphp

<div
    x-data="{
        stripe: null,
        elements: null,
        ready: false,
        processing: false,
        error: null,
        makeDefault: false,

        init() {
            if (! @js($this->setupIntentClientSecret)) {
                return
            }

            if (typeof Stripe === 'undefined') {
                const script = document.createElement('script')
                script.src = 'https://js.stripe.com/v3/'
                script.onload = () => this.mountStripeElement()
                document.head.appendChild(script)
            } else {
                this.mountStripeElement()
            }
        },

        mountStripeElement() {
            this.stripe = Stripe(@js(config('services.stripe.key')))

            const options = {
                clientSecret: @js($this->setupIntentClientSecret),
            }

            this.elements = this.stripe.elements(options)

            const paymentElement = this.elements.create('payment')
            paymentElement.mount('#billing-payment-element')
            paymentElement.on('ready', () => {
                this.ready = true
            })
        },

        async submitPaymentMethod() {
            if (! this.stripe || ! this.elements) {
                return
            }

            this.processing = true
            this.error = null

            const returnUrl = new URL(@js($returnUrl), window.location.origin)
            returnUrl.searchParams.set('make_default', this.makeDefault ? '1' : '0')

            const { error, setupIntent } = await this.stripe.confirmSetup({
                elements: this.elements,
                confirmParams: {
                    return_url: returnUrl.toString(),
                },
                redirect: 'if_required',
            })

            if (error) {
                this.error = error.message
                this.processing = false

                return
            }

            if (! setupIntent || ! setupIntent.payment_method) {
                this.error = 'Payment method setup could not be verified.'
                this.processing = false

                return
            }

            const paymentMethodId = typeof setupIntent.payment_method === 'string'
                ? setupIntent.payment_method
                : setupIntent.payment_method.id

            await $wire.paymentMethodSetupCompleted(setupIntent.id, paymentMethodId, this.makeDefault)
            this.processing = false
        },
    }"
    class="space-y-4"
>
    @if ($isForPaymentPlan)
        <p class="text-sm text-gray-600 dark:text-gray-300">
            This new payment method will be assigned to this payment plan after it is saved.
        </p>
    @endif

    <div id="billing-payment-element"></div>

    <label class="flex items-start gap-3 text-sm">
        <input
            type="checkbox"
            x-model="makeDefault"
            class="fi-checkbox-input mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600"
        >
        <span>
            <span class="font-medium">Make this my account default payment method</span>
            <span class="block text-gray-500 dark:text-gray-400">
                This does not change payment methods already assigned to payment plans.
            </span>
        </span>
    </label>

    <p x-show="error" x-text="error" class="text-sm text-danger-600 dark:text-danger-400"></p>

    <button
        type="button"
        x-on:click="submitPaymentMethod()"
        x-bind:disabled="processing || ! ready"
        class="fi-btn fi-btn-size-md fi-color-primary fi-btn-color-primary inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold shadow-sm outline-none transition duration-75 disabled:pointer-events-none disabled:opacity-70"
    >
        <span x-show="! processing">Save Payment Method</span>
        <span x-show="processing">Saving...</span>
    </button>
</div>
