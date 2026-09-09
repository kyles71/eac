<div
    x-data="paymentPlanPayment"
    data-client-secret="{{ $this->clientSecret }}"
    data-customer-session-client-secret="{{ $this->customerSessionClientSecret }}"
    data-publishable-key="{{ config('services.stripe.key') }}"
    data-requires-action="{{ $this->paymentAttempt?->status === \App\Enums\InstallmentPaymentAttemptStatus::RequiresAction ? 'true' : 'false' }}"
    data-return-url="{{ \App\Filament\User\Pages\PayPaymentPlan::getUrl(['paymentPlan' => $this->paymentPlan]) }}"
    data-use-for-future="{{ $this->paymentAttempt?->use_for_future ? 'true' : 'false' }}"
    class="space-y-4"
>
    <p x-show="requiresAction" class="text-sm text-gray-600 dark:text-gray-400">
        Your bank requires an additional verification step before this payment can be completed.
    </p>

    <div x-ref="paymentElement" x-show="! requiresAction" wire:ignore class="min-h-[120px]"></div>

    <label x-show="! requiresAction" class="flex items-start gap-3 text-sm">
        <input
            type="checkbox"
            x-model="useForFuture"
            class="fi-checkbox-input mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600"
        >
        <span>
            <span class="font-medium">Use the selected payment method for future installments on this plan</span>
            <span class="block text-gray-500 dark:text-gray-400">
                Future installments will be charged automatically using this payment method.
            </span>
        </span>
    </label>

    <div
        x-show="errorMessage"
        x-text="errorMessage"
        class="rounded-lg bg-danger-50 p-3 text-sm text-danger-600 dark:bg-danger-400/10 dark:text-danger-400"
    ></div>

    <button
        type="button"
        x-on:click="submitPayment()"
        x-bind:disabled="processing || ! ready"
        class="fi-btn fi-btn-size-lg relative inline-grid grid-flow-col items-center justify-center gap-1.5 rounded-lg bg-warning-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm outline-none transition duration-75 hover:bg-warning-500 focus-visible:ring-2 focus-visible:ring-warning-600 disabled:pointer-events-none disabled:opacity-70 dark:bg-warning-500 dark:hover:bg-warning-400 dark:focus-visible:ring-warning-500"
    >
        <template x-if="processing">
            <x-filament::loading-indicator class="h-5 w-5" />
        </template>
        <span x-show="! processing" x-text="requiresAction ? 'Complete Verification' : 'Pay Now'"></span>
        <span x-show="processing">Processing...</span>
    </button>
</div>

@script
<script>
    Alpine.data('paymentPlanPayment', () => ({
        stripe: null,
        elements: null,
        paymentElement: null,
        processing: false,
        ready: false,
        errorMessage: '',
        clientSecret: null,
        customerSessionClientSecret: null,
        publishableKey: null,
        requiresAction: false,
        returnUrl: null,
        useForFuture: false,

        init() {
            this.clientSecret = this.$el.dataset.clientSecret || null;
            this.customerSessionClientSecret = this.$el.dataset.customerSessionClientSecret || null;
            this.publishableKey = this.$el.dataset.publishableKey || null;
            this.requiresAction = this.$el.dataset.requiresAction === 'true';
            this.returnUrl = this.$el.dataset.returnUrl || null;
            this.useForFuture = this.$el.dataset.useForFuture === 'true';

            if (! this.clientSecret || ! this.publishableKey || ! this.returnUrl) {
                this.errorMessage = 'The secure payment form is not ready. Retry payment setup.';

                return;
            }

            if (typeof window.Stripe === 'function') {
                this.mountStripeElement();

                return;
            }

            const existingScript = Array.from(document.scripts)
                .find((script) => script.src === 'https://js.stripe.com/v3/');
            const script = existingScript || document.createElement('script');

            script.addEventListener('load', () => this.mountStripeElement(), { once: true });
            script.addEventListener('error', () => {
                this.errorMessage = 'The secure payment form could not be loaded. Check your connection and try again.';
            }, { once: true });

            if (! existingScript) {
                script.src = 'https://js.stripe.com/v3/';
                script.async = true;
                document.head.appendChild(script);
            }
        },

        mountStripeElement() {
            if (typeof window.Stripe !== 'function') {
                this.errorMessage = 'The secure payment form could not be initialized. Retry payment setup.';

                return;
            }

            try {
                this.stripe = window.Stripe(this.publishableKey);

                if (this.requiresAction) {
                    this.ready = true;

                    return;
                }

                const elementsOptions = {
                    clientSecret: this.clientSecret,
                    appearance: {
                        theme: 'stripe',
                        variables: {
                            colorPrimary: '#3b82f6',
                            borderRadius: '8px',
                        },
                    },
                };

                if (this.customerSessionClientSecret) {
                    elementsOptions.customerSessionClientSecret = this.customerSessionClientSecret;
                }

                this.elements = this.stripe.elements(elementsOptions);
                this.paymentElement = this.elements.create('payment');
                this.paymentElement.on('ready', () => { this.ready = true; });
                this.paymentElement.mount(this.$refs.paymentElement);
            } catch (error) {
                this.errorMessage = 'The secure payment form could not be initialized. Retry payment setup.';
            }
        },

        async submitPayment() {
            if (this.processing || ! this.ready || ! this.stripe) {
                return;
            }

            this.processing = true;
            this.errorMessage = '';

            try {
                if (! this.requiresAction) {
                    const preference = await this.$wire.configureFuturePayments(this.useForFuture);

                    if (! preference.successful) {
                        this.errorMessage = preference.message || 'The future payment preference could not be updated.';

                        return;
                    }
                }

                const confirmParams = { return_url: this.returnUrl };

                if (this.useForFuture) {
                    confirmParams.payment_method_data = { allow_redisplay: 'always' };
                }

                const confirmation = this.requiresAction
                    ? await this.stripe.handleNextAction({ clientSecret: this.clientSecret })
                    : await this.stripe.confirmPayment({
                        elements: this.elements,
                        confirmParams,
                        redirect: 'if_required',
                    });

                if (confirmation.error) {
                    this.errorMessage = confirmation.error.message;

                    return;
                }

                if (! confirmation.paymentIntent) {
                    this.errorMessage = 'The payment status could not be confirmed. Please refresh and try again.';

                    return;
                }

                const result = await this.$wire.completePayment(confirmation.paymentIntent.id);

                if (result.status === 'Succeeded' || result.status === 'Processing') {
                    window.location.assign(this.returnUrl);

                    return;
                }

                this.errorMessage = result.message || 'The payment was not completed. Please try another payment method.';
            } catch (error) {
                this.errorMessage = error instanceof Error && error.message
                    ? error.message
                    : 'The payment could not be submitted. Please try again.';
            } finally {
                this.processing = false;
            }
        },
    }));
</script>
@endscript
