<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoardItemPriority;
use App\Enums\BoardItemType;
use App\Support\MediaDisks;
use Database\Factories\BoardItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class BoardItem extends Model implements HasMedia
{
    /** @use HasFactory<BoardItemFactory> */
    use HasFactory, InteractsWithMedia;

    protected $casts = [
        'id' => 'integer',
        'board_id' => 'integer',
        'board_stage_id' => 'integer',
        'created_by_user_id' => 'integer',
        'type' => BoardItemType::class,
        'priority' => BoardItemPriority::class,
        'position' => 'decimal:10',
        'due_date' => 'date',
        'archived_at' => 'datetime',
    ];

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return BelongsTo<BoardStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(BoardStage::class, 'board_stage_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'board_item_assignees')
            ->withTimestamps();
    }

    /** @return HasMany<BoardItemComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(BoardItemComment::class)->oldest();
    }

    /** @return HasMany<BoardItemActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(BoardItemActivity::class)->latest();
    }

    /** @return HasMany<BoardItemSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(BoardItemSubscription::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk(MediaDisks::private());
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
