<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TextMessageBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int, array{id: int, name: string, start_time: ?string, cancelled: bool}> $events
 * @property array{date: string, time: string}|null $selection
 * @property list<string> $warnings
 */
final class TextMessageBatch extends Model
{
    /** @use HasFactory<TextMessageBatchFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<TextMessageRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(TextMessageRecipient::class, 'batch_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'selection' => 'array',
            'warnings' => 'array',
        ];
    }
}
