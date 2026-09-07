<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MediaDisks;
use Database\Factories\BoardItemCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class BoardItemComment extends Model implements HasMedia
{
    /** @use HasFactory<BoardItemCommentFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $casts = [
        'id' => 'integer',
        'board_item_id' => 'integer',
        'author_id' => 'integer',
        'edited_at' => 'datetime',
    ];

    /** @return BelongsTo<BoardItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'board_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk(MediaDisks::private());
    }
}
