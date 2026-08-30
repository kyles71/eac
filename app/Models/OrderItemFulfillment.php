<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class OrderItemFulfillment extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFulfillmentFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'order_item_id' => 'integer',
        'unit_number' => 'integer',
        'source_id' => 'integer',
        'fulfilled_by_user_id' => 'integer',
        'fulfilled_at' => 'datetime',
        'voided_by_user_id' => 'integer',
        'voided_at' => 'datetime',
    ];

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->voided_at === null;
    }

    public function sourceLabel(): string
    {
        if ($this->source_type === (new Event)->getMorphClass()) {
            return $this->source instanceof Event
                ? 'Event: '.$this->source->name
                : 'Event #'.$this->source_id.' (deleted)';
        }

        return $this->source_type === null
            ? 'Manual fulfillment'
            : class_basename($this->source_type).' #'.$this->source_id;
    }
}
