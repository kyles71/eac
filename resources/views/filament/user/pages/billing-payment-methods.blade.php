@php
    $returnUrl = \App\Filament\User\Pages\Billing::getUrl(['tab' => 'payment-methods']);
@endphp

<div
    x-data="{
        stripe: null,
        elements: null,
        ready: false,
        processing: false,
        error: null,

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

            if (@js($this->customerSessionClientSecret)) {
                options.customerSessionClientSecret = @js($this->customerSessionClientSecret)
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

            const { error } = await this.stripe.confirmSetup({
                elements: this.elements,
                confirmParams: {
                    return_url: @js($returnUrl),
                },
                redirect: 'if_required',
            })

            if (error) {
                this.error = error.message
                this.processing = false

                return
            }

            await $wire.paymentMethodSetupCompleted()
            this.processing = false
        },
    }"
    class="space-y-4"
>
    <div id="billing-payment-element"></div>

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
