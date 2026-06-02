<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class LegalDocumentVersion extends Model
{
    /** @use HasFactory<\Database\Factories\LegalDocumentVersionFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'legal_document_id' => 'integer',
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    /** @return BelongsTo<LegalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    /** @return HasMany<LegalDocumentAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(LegalDocumentAcceptance::class);
    }

    public function versionLabel(): string
    {
        return "Version {$this->version}";
    }

    protected static function booted(): void
    {
        self::updating(function (LegalDocumentVersion $version): void {
            if (
                $version->getOriginal('published_at') !== null
                && $version->isDirty(['legal_document_id', 'version', 'title', 'content', 'published_at'])
            ) {
                throw new LogicException('Published legal document versions are immutable. Publish a new version instead.');
            }
        });
    }
}
