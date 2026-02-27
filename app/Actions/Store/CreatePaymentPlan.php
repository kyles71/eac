<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use Carbon\Carbon;

final readonly class CreatePaymentPlan
{
    /**
     * Create a payment plan for an order based on a template.
     * The first installment is marked as Paid (already collected at checkout).
     */
    public function handle(
        Order $order,
        PaymentPlanTemplate $template,
        PaymentPlanMethod $method,
        ?string $stripeCustomerId = null,
        ?string $stripePaymentMethodId = null,
    ): PaymentPlan {
        $total = $order->total;
        $amounts = $template->installmentAmounts($total);

        /** @var PaymentPlan $paymentPlan */
        $paymentPlan = $order->paymentPlan()->create([
            'payment_plan_template_id' => $template->id,
            'method' => $method,
            'total_amount' => $total,
            'number_of_installments' => $template->number_of_installments,
            'frequency' => $template->frequency,
            'stripe_customer_id' => $stripeCustomerId,
            'stripe_payment_method_id' => $stripePaymentMethodId,
        ]);

        // Create first installment (already paid at checkout)
        $paymentPlan->installments()->create([
            'installment_number' => 1,
            'amount' => $amounts['first'],
            'due_date' => Carbon::today(),
            'status' => InstallmentStatus::Paid,
            'paid_at' => now(),
        ]);

        // Create remaining installments
        $intervalDays = $template->frequency->intervalDays();

        for ($i = 2; $i <= $template->number_of_installments; $i++) {
            $paymentPlan->installments()->create([
                'installment_number' => $i,
                'amount' => $amounts['remaining'],
                'due_date' => Carbon::today()->addDays($intervalDays * ($i - 1)),
                'status' => InstallmentStatus::Pending,
            ]);
        }

        return $paymentPlan;
    }
}
