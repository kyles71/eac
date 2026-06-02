<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class LegalDocumentAcceptance extends Model
{
    /** @use HasFactory<\Database\Factories\LegalDocumentAcceptanceFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'legal_document_version_id' => 'integer',
        'user_id' => 'integer',
        'accepted_at' => 'datetime',
    ];

    /** @return BelongsTo<LegalDocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'legal_document_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function acceptable(): MorphTo
    {
        return $this->morphTo();
    }
}
