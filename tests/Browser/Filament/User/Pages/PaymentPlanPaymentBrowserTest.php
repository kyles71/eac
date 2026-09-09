<?php

declare(strict_types=1);

beforeEach(function (): void {
    auth()->logout();
});

it('runs the Payment Element confirmation flow in a real browser', function (): void {
    $page = visit('/admin/login')
        ->assertVisible('form');

    $page->script(<<<'JS'
        window.__paymentPlanStripeCalls = {
            completePayment: [],
            configureFuturePayments: [],
            confirmPayment: [],
            elements: [],
            mounted: false,
        };
        window.Stripe = (publishableKey) => ({
            elements: (options) => {
                window.__paymentPlanStripeCalls.elements.push({ publishableKey, options });

                return {
                    create: () => ({
                        on: (event, callback) => {
                            if (event === 'ready') {
                                queueMicrotask(callback);
                            }
                        },
                        mount: () => { window.__paymentPlanStripeCalls.mounted = true; },
                    }),
                };
            },
            confirmPayment: async (options) => {
                window.__paymentPlanStripeCalls.confirmPayment.push(options);

                return { paymentIntent: { id: 'pi_browser_confirmation' } };
            },
            handleNextAction: async () => ({ paymentIntent: { id: 'pi_unused' } }),
        });
        Alpine.magic('wire', () => ({
            configureFuturePayments: async (useForFuture) => {
                window.__paymentPlanStripeCalls.configureFuturePayments.push(useForFuture);

                return { successful: true, message: null };
            },
            completePayment: async (paymentIntentId) => {
                window.__paymentPlanStripeCalls.completePayment.push(paymentIntentId);

                return { status: 'Failed', message: 'Declined in browser test.' };
            },
        }));
        JS);
    $page->script(paymentPlanPaymentComponentScript());
    $page->script(<<<'JS'
        const harness = document.createElement('div');
        harness.id = 'payment-plan-payment-harness';
        harness.setAttribute('x-data', 'paymentPlanPayment');
        harness.dataset.clientSecret = 'pi_browser_secret';
        harness.dataset.customerSessionClientSecret = 'cuss_browser_secret';
        harness.dataset.publishableKey = 'pk_test_browser';
        harness.dataset.requiresAction = 'false';
        harness.dataset.returnUrl = '/payment-return';
        harness.dataset.useForFuture = 'false';
        harness.innerHTML = `
            <div x-ref="paymentElement"></div>
            <input type="checkbox" x-model="useForFuture">
            <p data-error x-text="errorMessage"></p>
            <button type="button" x-on:click="submitPayment()">Pay Now</button>
        `;
        document.body.appendChild(harness);
        Alpine.initTree(harness);
        JS);

    $page
        ->wait(0.1)
        ->click('#payment-plan-payment-harness input')
        ->click('#payment-plan-payment-harness button')
        ->wait(0.1)
        ->assertSee('Declined in browser test.')
        ->assertNoJavaScriptErrors();

    $calls = $page->script(<<<'JS'
        ({
            completePayment: window.__paymentPlanStripeCalls.completePayment,
            configureFuturePayments: window.__paymentPlanStripeCalls.configureFuturePayments,
            confirmParams: window.__paymentPlanStripeCalls.confirmPayment[0].confirmParams,
            redirect: window.__paymentPlanStripeCalls.confirmPayment[0].redirect,
            hasElements: window.__paymentPlanStripeCalls.confirmPayment[0].elements !== undefined,
            elementPublishableKey: window.__paymentPlanStripeCalls.elements[0].publishableKey,
            elementClientSecret: window.__paymentPlanStripeCalls.elements[0].options.clientSecret,
            elementCustomerSessionClientSecret: window.__paymentPlanStripeCalls.elements[0].options.customerSessionClientSecret,
            mounted: window.__paymentPlanStripeCalls.mounted,
        })
        JS);

    expect($calls)->toBe([
        'completePayment' => ['pi_browser_confirmation'],
        'configureFuturePayments' => [true],
        'confirmParams' => [
            'return_url' => '/payment-return',
            'payment_method_data' => ['allow_redisplay' => 'always'],
        ],
        'redirect' => 'if_required',
        'hasElements' => true,
        'elementPublishableKey' => 'pk_test_browser',
        'elementClientSecret' => 'pi_browser_secret',
        'elementCustomerSessionClientSecret' => 'cuss_browser_secret',
        'mounted' => true,
    ]);
});

it('runs the additional-authentication flow without changing the future-payment preference', function (): void {
    $page = visit('/admin/login')
        ->assertVisible('form');

    $page->script(<<<'JS'
        window.__paymentPlanStripeCalls = {
            completePayment: [],
            configureFuturePayments: [],
            handleNextAction: [],
        };
        window.Stripe = () => ({
            elements: () => { throw new Error('Payment Element should not mount for requires_action.'); },
            confirmPayment: async () => { throw new Error('confirmPayment should not run for requires_action.'); },
            handleNextAction: async (options) => {
                window.__paymentPlanStripeCalls.handleNextAction.push(options);

                return { paymentIntent: { id: 'pi_browser_action' } };
            },
        });
        Alpine.magic('wire', () => ({
            configureFuturePayments: async (useForFuture) => {
                window.__paymentPlanStripeCalls.configureFuturePayments.push(useForFuture);

                return { successful: true, message: null };
            },
            completePayment: async (paymentIntentId) => {
                window.__paymentPlanStripeCalls.completePayment.push(paymentIntentId);

                return { status: 'Failed', message: 'Authentication was not completed.' };
            },
        }));
        JS);
    $page->script(paymentPlanPaymentComponentScript());
    $page->script(<<<'JS'
        const harness = document.createElement('div');
        harness.id = 'payment-plan-action-harness';
        harness.setAttribute('x-data', 'paymentPlanPayment');
        harness.dataset.clientSecret = 'pi_action_secret';
        harness.dataset.publishableKey = 'pk_test_browser';
        harness.dataset.requiresAction = 'true';
        harness.dataset.returnUrl = '/payment-return';
        harness.dataset.useForFuture = 'false';
        harness.innerHTML = `
            <div x-ref="paymentElement"></div>
            <p data-error x-text="errorMessage"></p>
            <button type="button" x-on:click="submitPayment()">Complete Verification</button>
        `;
        document.body.appendChild(harness);
        Alpine.initTree(harness);
        JS);

    $page
        ->wait(0.1)
        ->click('#payment-plan-action-harness button')
        ->wait(0.1)
        ->assertSee('Authentication was not completed.')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.__paymentPlanStripeCalls'))->toBe([
        'completePayment' => ['pi_browser_action'],
        'configureFuturePayments' => [],
        'handleNextAction' => [['clientSecret' => 'pi_action_secret']],
    ]);
});

function paymentPlanPaymentComponentScript(): string
{
    $view = file_get_contents(resource_path('views/filament/user/pages/pay-payment-plan-payment.blade.php'));

    expect($view)->toBeString();
    preg_match('/<script>(.*?)<\/script>/s', $view, $matches);

    expect($matches)->toHaveKey(1);

    return (string) $matches[1];
}
