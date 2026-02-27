<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentPlanFrequency;
use App\Enums\PaymentPlanMethod;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlan>
 */
final class PaymentPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'payment_plan_template_id' => PaymentPlanTemplate::factory(),
            'method' => PaymentPlanMethod::AutoCharge,
            'total_amount' => 10000,
            'number_of_installments' => 3,
            'frequency' => PaymentPlanFrequency::Monthly,
            'stripe_customer_id' => 'cus_test_'.fake()->uuid(),
            'stripe_payment_method_id' => 'pm_test_'.fake()->uuid(),
        ];
    }

    /**
     * Use manual invoice method.
     */
    public function manualInvoice(): static
    {
        return $this->state(fn (array $attributes): array => [
            'method' => PaymentPlanMethod::ManualInvoice,
            'stripe_payment_method_id' => null,
        ]);
    }
}
