<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ManagedBannerDismissal extends Model
{
    /** @use HasFactory<\Database\Factories\ManagedBannerDismissalFactory> */
    use HasFactory;

    protected $casts = [
        'dismissed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<ManagedBanner, $this>
     */
    public function managedBanner(): BelongsTo
    {
        return $this->belongsTo(ManagedBanner::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
