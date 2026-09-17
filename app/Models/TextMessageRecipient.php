<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TextMessageStatus;
use Database\Factories\TextMessageRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array{student_id: int, contact_id: int, student: string, contact: string}> $sources
 * @property array<string, int|string|null>|null $provider_receipt
 */
final class TextMessageRecipient extends Model
{
    /** @use HasFactory<TextMessageRecipientFactory> */
    use HasFactory;

    /** @return BelongsTo<TextMessageBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(TextMessageBatch::class, 'batch_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TextMessageStatus::class,
            'sources' => 'array',
            'provider_receipt' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
