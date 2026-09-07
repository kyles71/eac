<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BoardInteractionMode;
use App\Enums\BoardItemType;
use App\Enums\BoardMemberRole;
use Database\Factories\BoardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Board extends Model
{
    /** @use HasFactory<BoardFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'created_by_user_id' => 'integer',
        'interaction_mode' => BoardInteractionMode::class,
        'allowed_item_types' => 'array',
        'archived_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<BoardStage, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(BoardStage::class)->orderBy('sort_order');
    }

    /** @return HasMany<BoardStage, $this> */
    public function activeStages(): HasMany
    {
        return $this->stages()->whereNull('archived_at');
    }

    /** @return HasMany<BoardMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(BoardMembership::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'board_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function managers(): BelongsToMany
    {
        return $this->members()->wherePivot('role', BoardMemberRole::Manager->value);
    }

    /** @return HasMany<BoardItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BoardItem::class);
    }

    /** @return Builder<Board> */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('ViewAny:Board')) {
            return $query;
        }

        return $query->whereHas(
            'memberships',
            fn (Builder $query): Builder => $query->where('user_id', $user->id),
        );
    }

    public function membershipRoleFor(User $user): ?BoardMemberRole
    {
        $role = $this->memberships()
            ->where('user_id', $user->id)
            ->value('role');

        if ($role instanceof BoardMemberRole) {
            return $role;
        }

        return is_string($role) ? BoardMemberRole::tryFrom($role) : null;
    }

    public function defaultStage(): ?BoardStage
    {
        return $this->activeStages()->where('is_default', true)->first()
            ?? $this->activeStages()->first();
    }

    public function allowsItemType(BoardItemType $type): bool
    {
        return in_array($type->value, $this->allowed_item_types ?? [], true);
    }

    /** @return array<string, string> */
    public function itemTypeOptions(): array
    {
        return collect(BoardItemType::cases())
            ->filter(fn (BoardItemType $type): bool => $this->allowsItemType($type))
            ->mapWithKeys(fn (BoardItemType $type): array => [$type->value => $type->getLabel()])
            ->all();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
