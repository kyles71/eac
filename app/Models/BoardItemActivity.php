<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoardItemActivityType;
use Database\Factories\BoardItemActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BoardItemActivity extends Model
{
    /** @use HasFactory<BoardItemActivityFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'board_item_id' => 'integer',
        'actor_id' => 'integer',
        'type' => BoardItemActivityType::class,
        'metadata' => 'array',
    ];

    /** @return BelongsTo<BoardItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BoardItem::class, 'board_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function description(): string
    {
        return match ($this->type) {
            BoardItemActivityType::Created => 'created this card',
            BoardItemActivityType::StageChanged => sprintf(
                'moved this card from %s to %s',
                $this->metadata['from_name'] ?? 'another stage',
                $this->metadata['to_name'] ?? 'another stage',
            ),
            BoardItemActivityType::AssigneesChanged => 'updated the assignees',
            BoardItemActivityType::Archived => 'archived this card',
            BoardItemActivityType::Restored => 'restored this card',
        };
    }
}
