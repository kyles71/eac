<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class LegalDocument extends Model
{
    /** @use HasFactory<\Database\Factories\LegalDocumentFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
    ];

    /** @return HasMany<LegalDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(LegalDocumentVersion::class);
    }

    /** @return HasOne<LegalDocumentVersion, $this> */
    public function latestPublishedVersion(): HasOne
    {
        return $this->hasOne(LegalDocumentVersion::class)
            ->ofMany(['version' => 'max'], fn ($query) => $query->whereNotNull('published_at'));
    }

    public function currentVersion(): ?LegalDocumentVersion
    {
        return $this->latestPublishedVersion()->first();
    }

    public function publishVersion(string $title, string $content): LegalDocumentVersion
    {
        $nextVersion = ((int) $this->versions()->max('version')) + 1;

        /** @var LegalDocumentVersion $version */
        $version = $this->versions()->create([
            'version' => $nextVersion,
            'title' => $title,
            'content' => $content,
            'published_at' => now(),
        ]);

        return $version;
    }
}
