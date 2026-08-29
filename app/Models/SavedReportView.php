<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportKey;
use App\Enums\SavedReportViewVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SavedReportView extends Model
{
    /** @use HasFactory<\Database\Factories\SavedReportViewFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'report_key' => ReportKey::class,
        'visibility' => SavedReportViewVisibility::class,
        'state' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->visibility === SavedReportViewVisibility::Template;
    }

    /** @param Builder<SavedReportView> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query): Builder => $query
            ->where('user_id', $user->id)
            ->orWhere('visibility', SavedReportViewVisibility::Template));
    }
}
