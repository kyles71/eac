<div
    x-data="{
        stripe: null,
        elements: null,
        cardElement: null,
        processing: false,
        errorMessage: '',
        clientSecret: @js($clientSecret),
        stripeKey: @js($stripeKey),

        async init() {
            await this.loadStripe()
            if (this.stripe && this.clientSecret) {
                this.elements = this.stripe.elements({
                    clientSecret: this.clientSecret,
                })
                this.cardElement = this.elements.create('payment')
                this.$nextTick(() => {
                    const container = document.getElementById('payment-element')
                    if (container) {
                        this.cardElement.mount('#payment-element')
                    }
                })
            }
        },

        async loadStripe() {
            if (window.Stripe) {
                this.stripe = window.Stripe(this.stripeKey)
                return
            }
            return new Promise((resolve) => {
                const script = document.createElement('script')
                script.src = 'https://js.stripe.com/v3/'
                script.onload = () => {
                    this.stripe = window.Stripe(this.stripeKey)
                    resolve()
                }
                document.head.appendChild(script)
            })
        },

        async submitPayment() {
            if (this.processing) return
            this.processing = true
            this.errorMessage = ''

            const { error, paymentIntent } = await this.stripe.confirmPayment({
                elements: this.elements,
                redirect: 'if_required',
            })

            if (error) {
                this.errorMessage = error.message
                this.processing = false
                return
            }

            if (paymentIntent && paymentIntent.status === 'succeeded') {
                const pm = paymentIntent.payment_method
                let brand = 'Card'
                let last4 = '****'

                if (typeof pm === 'object' && pm !== null) {
                    brand = pm.card?.brand || pm.type || 'Card'
                    last4 = pm.card?.last4 || '****'
                } else if (typeof pm === 'string') {
                    try {
                        const pmResult = await this.stripe.retrievePaymentMethod(pm)
                        if (pmResult.paymentMethod) {
                            brand = pmResult.paymentMethod.card?.brand || pmResult.paymentMethod.type || 'Card'
                            last4 = pmResult.paymentMethod.card?.last4 || '****'
                        }
                    } catch (e) {
                        // Payment method details are non-critical; defaults are used for display only
                        console.warn('Could not retrieve payment method details:', e.message)
                    }
                }

                $wire.setPaymentMethod(pm?.id || pm || '', brand, last4)
                $wire.paymentConfirmed()
            } else {
                this.errorMessage = 'Payment was not completed. Please try again.'
                this.processing = false
            }
        }
    }"
>
    <div id="payment-element" class="mb-4"></div>

    <div x-show="errorMessage" x-text="errorMessage" class="text-sm text-danger-600 dark:text-danger-400 mb-4"></div>

    <x-filament::button
        x-on:click="submitPayment()"
        x-bind:disabled="processing"
        color="success"
        class="w-full"
    >
        <span x-show="!processing">Pay & Confirm</span>
        <span x-show="processing">Processing...</span>
    </x-filament::button>
</div>
