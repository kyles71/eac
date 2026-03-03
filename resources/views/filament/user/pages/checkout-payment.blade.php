<div x-data="{
    stripe: null,
    elements: null,
    paymentElement: null,
    processing: false,
    errorMessage: '',
    init() {
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
        const clientSecret = @js($this->clientSecret)

        if (!clientSecret) return

        this.stripe = Stripe(@js(config('services.stripe.key')))
        this.elements = this.stripe.elements({
            clientSecret: clientSecret,
            appearance: {
                theme: 'stripe',
                variables: {
                    colorPrimary: '#3b82f6',
                    borderRadius: '8px',
                },
            },
        })

        this.paymentElement = this.elements.create('payment')
        this.paymentElement.mount('#payment-element')
    },
    async submitPayment() {
        if (this.processing) return
        this.processing = true
        this.errorMessage = ''

        // Check if using a saved payment method
        const savedMethod = $wire.selectedSavedPaymentMethod
        if (savedMethod && savedMethod !== 'new') {
            await $wire.confirmWithSavedMethod()
            this.processing = false
            return
        }

        const { error } = await this.stripe.confirmPayment({
            elements: this.elements,
            confirmParams: {
                return_url: @js(\App\Filament\User\Pages\CheckoutSuccess::getUrl()) + '?order_id=' + @js($this->order?->id),
            },
        })

        if (error) {
            this.errorMessage = error.message
            this.processing = false
        }
    },
}" class="space-y-4">
    <div id="payment-element" x-show="!$wire.selectedSavedPaymentMethod || $wire.selectedSavedPaymentMethod === 'new'"
        class="min-h-[120px]"></div>

    <div x-show="errorMessage" x-text="errorMessage"
        class="rounded-lg bg-danger-50 p-3 text-sm text-danger-600 dark:bg-danger-400/10 dark:text-danger-400"></div>

    <button type="button" x-on:click="submitPayment()" x-bind:disabled="processing"
        class="fi-btn fi-btn-size-lg relative inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg bg-warning-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm outline-none transition duration-75 hover:bg-warning-500 focus-visible:ring-2 focus-visible:ring-warning-600 disabled:pointer-events-none disabled:opacity-70 dark:bg-warning-500 dark:hover:bg-warning-400 dark:focus-visible:ring-warning-500">
        <template x-if="processing">
            <x-filament::loading-indicator class="h-5 w-5" />
        </template>
        <span x-show="!processing">Pay Now</span>
        <span x-show="processing">Processing...</span>
    </button>
</div>
