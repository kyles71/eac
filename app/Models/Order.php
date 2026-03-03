<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentPlanMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status' => OrderStatus::class,
        'subtotal' => 'integer',
        'total' => 'integer',
        'discount_code_id' => 'integer',
        'discount_amount' => 'integer',
        'credit_applied' => 'integer',
        'restricted_credit_applied' => 'integer',
        'payment_plan_template_id' => 'integer',
        'payment_plan_method' => PaymentPlanMethod::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function paymentPlanTemplate(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanTemplate::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentPlan(): HasOne
    {
        return $this->hasOne(PaymentPlan::class);
    }

    /**
     * Get the formatted subtotal in dollars.
     */
    public function formattedSubtotal(): string
    {
        return '$'.number_format($this->subtotal / 100, 2);
    }

    /**
     * Get the formatted total in dollars.
     */
    public function formattedTotal(): string
    {
        return '$'.number_format($this->total / 100, 2);
    }
}
