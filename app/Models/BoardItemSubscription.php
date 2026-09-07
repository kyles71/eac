<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BoardItemSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BoardItemSubscription extends Model
{
    /** @use HasFactory<BoardItemSubscriptionFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'board_item_id' => 'integer',
        'user_id' => 'integer',
        'muted_at' => 'datetime',
    ];

    /** @return BelongsTo<BoardItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'board_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->muted_at === null;
    }
}
