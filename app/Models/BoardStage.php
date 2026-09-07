<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoardStageKind;
use Database\Factories\BoardStageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BoardStage extends Model
{
    /** @use HasFactory<BoardStageFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'board_id' => 'integer',
        'sort_order' => 'integer',
        'kind' => BoardStageKind::class,
        'is_default' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return HasMany<BoardItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BoardItem::class);
    }

    /**
     * @param  Builder<BoardStage>  $query
     * @return Builder<BoardStage>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
