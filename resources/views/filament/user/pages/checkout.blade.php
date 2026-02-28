<x-filament-panels::page>
    <div
        x-data="{
            step: @entangle('currentStep'),
            stripe: null,
            elements: null,
            cardElement: null,
            processing: false,
            errorMessage: '',
            clientSecret: @js($clientSecret),
            stripeKey: @js($this->getStripeKey()),

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

            async submitPaymentMethod() {
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
        class="space-y-6"
    >
        {{-- Step Indicators --}}
        <nav aria-label="Progress">
            <ol role="list" class="flex items-center justify-center space-x-8">
                <li class="flex items-center">
                    <span
                        :class="step >= 1
                            ? 'bg-primary-600 text-white'
                            : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium"
                    >
                        1
                    </span>
                    <span class="ml-2 text-sm font-medium text-gray-900 dark:text-white">Payment Details</span>
                </li>
                <li class="flex items-center">
                    <div class="h-0.5 w-12 bg-gray-200 dark:bg-gray-700"></div>
                </li>
                <li class="flex items-center">
                    <span
                        :class="step >= 2
                            ? 'bg-primary-600 text-white'
                            : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium"
                    >
                        2
                    </span>
                    <span class="ml-2 text-sm font-medium text-gray-900 dark:text-white">Confirmation</span>
                </li>
            </ol>
        </nav>

        {{-- Step 1: Payment Details --}}
        <div x-show="step === 1" x-transition>
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-content-ctn">
                    <div class="fi-section-content p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Enter Payment Details</h3>

                        {{-- Order Summary --}}
                        <div class="mb-6 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Order Summary</h4>
                            @foreach ($cartSummary as $item)
                                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                                    <span>{{ $item['name'] }} × {{ $item['quantity'] }}</span>
                                    <span>{{ $this->formatCents($item['total_price']) }}</span>
                                </div>
                            @endforeach
                            <div class="mt-2 border-t border-gray-200 pt-2 dark:border-gray-700">
                                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                                    <span>Subtotal</span>
                                    <span>{{ $this->formatCents($subtotal) }}</span>
                                </div>
                                @if ($discountDisplay)
                                    <div class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                        <span>Discount: {{ $discountDisplay }}</span>
                                    </div>
                                @endif
                                @if ($creditDisplay)
                                    <div class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                        <span>Store Credit: {{ $creditDisplay }}</span>
                                    </div>
                                @endif
                                @if ($paymentPlanDisplay)
                                    <div class="flex justify-between text-sm text-blue-600 dark:text-blue-400">
                                        <span>Payment Plan: {{ $paymentPlanDisplay }}</span>
                                    </div>
                                @endif
                                <div class="flex justify-between text-sm font-semibold text-gray-900 dark:text-white mt-1">
                                    <span>Amount Due Now</span>
                                    <span>{{ $this->formatCents($checkoutAmount) }}</span>
                                </div>
                            </div>
                        </div>

                        {{-- Stripe Payment Element --}}
                        <div id="payment-element" class="mb-4"></div>

                        {{-- Error Message --}}
                        <div x-show="errorMessage" x-text="errorMessage" class="text-sm text-danger-600 dark:text-danger-400 mb-4"></div>

                        {{-- Actions --}}
                        <div class="flex justify-between mt-6">
                            <a
                                href="{{ \App\Filament\User\Pages\Cart::getUrl() }}"
                                class="fi-btn fi-btn-size-md fi-color-gray relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-gray gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-white text-gray-950 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20"
                            >
                                Back to Cart
                            </a>
                            <button
                                @click="submitPaymentMethod()"
                                :disabled="processing"
                                class="fi-btn fi-btn-size-md fi-color-primary relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <span x-show="!processing">Pay & Confirm</span>
                                <span x-show="processing">Processing...</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 2: Confirmation --}}
        <div x-show="step === 2" x-transition>
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-content-ctn">
                    <div class="fi-section-content p-6">
                        <div class="text-center mb-6">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-success-100 dark:bg-success-500/20">
                                <x-heroicon-o-check class="h-6 w-6 text-success-600 dark:text-success-400" />
                            </div>
                            <h3 class="mt-3 text-lg font-medium text-gray-900 dark:text-white">Payment Successful!</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Your order has been confirmed.</p>
                        </div>

                        {{-- Order Details --}}
                        <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800 mb-4">
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Order Summary</h4>
                            @foreach ($cartSummary as $item)
                                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                                    <span>{{ $item['name'] }} × {{ $item['quantity'] }}</span>
                                    <span>{{ $this->formatCents($item['total_price']) }}</span>
                                </div>
                            @endforeach
                            <div class="mt-2 border-t border-gray-200 pt-2 dark:border-gray-700">
                                @if ($discountDisplay)
                                    <div class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                        <span>Discount: {{ $discountDisplay }}</span>
                                    </div>
                                @endif
                                @if ($creditDisplay)
                                    <div class="flex justify-between text-sm text-green-600 dark:text-green-400">
                                        <span>Store Credit: {{ $creditDisplay }}</span>
                                    </div>
                                @endif
                                <div class="flex justify-between text-sm font-semibold text-gray-900 dark:text-white mt-1">
                                    <span>Total Charged</span>
                                    <span>{{ $this->formatCents($checkoutAmount) }}</span>
                                </div>
                            </div>
                        </div>

                        {{-- Payment Method --}}
                        <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800 mb-6">
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Payment Method</h4>
                            <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                <x-heroicon-o-credit-card class="h-5 w-5 mr-2" />
                                <span class="capitalize">{{ $cardBrand }}</span>
                                <span class="ml-1">ending in {{ $cardLast4 }}</span>
                            </div>
                        </div>

                        @if ($paymentPlanDisplay)
                            <div class="rounded-lg bg-blue-50 p-4 dark:bg-blue-500/10 mb-6">
                                <h4 class="text-sm font-medium text-blue-700 dark:text-blue-300 mb-1">Payment Plan</h4>
                                <p class="text-sm text-blue-600 dark:text-blue-400">{{ $paymentPlanDisplay }}</p>
                                <p class="text-xs text-blue-500 dark:text-blue-500 mt-1">
                                    Remaining installments will be charged according to your plan schedule.
                                </p>
                            </div>
                        @endif

                        {{-- Navigation --}}
                        <div class="flex justify-center space-x-3">
                            <a
                                href="{{ \App\Filament\User\Pages\MyEnrollments::getUrl() }}"
                                class="fi-btn fi-btn-size-md fi-color-primary relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400"
                            >
                                View My Classes
                            </a>
                            <a
                                href="{{ \App\Filament\User\Pages\Store::getUrl() }}"
                                class="fi-btn fi-btn-size-md fi-color-gray relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-gray gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-white text-gray-950 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20"
                            >
                                Continue Shopping
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
