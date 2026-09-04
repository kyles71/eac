<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoardMemberRole;
use Database\Factories\BoardMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BoardMembership extends Model
{
    /** @use HasFactory<BoardMembershipFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'board_id' => 'integer',
        'user_id' => 'integer',
        'role' => BoardMemberRole::class,
    ];

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
