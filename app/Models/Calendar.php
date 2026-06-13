<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\CompetitionRosterService;
use ArrayAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Tags\HasTags;
use Spatie\Tags\Tag;

final class Calendar extends Model
{
    /** @use HasFactory<\Database\Factories\CalendarFactory> */
    use HasFactory;

    use HasTags {
        attachTag as protected attachTagFromTrait;
        attachTags as protected attachTagsFromTrait;
        syncTagIds as protected syncTagIdsFromTrait;
        syncTags as protected syncTagsFromTrait;
        syncTagsWithType as protected syncTagsWithTypeFromTrait;
    }

    public const string SLUG_MY = 'my';

    public const string SLUG_EAC = 'eac';

    public const string SLUG_OWNERS = 'owners';

    public const string SLUG_STAFF = 'staff';

    public const string SLUG_COMP = 'comp';

    public const string AUDIENCE_TAG_TYPE = 'calendar-audience';

    public const string AUDIENCE_TAG_PUBLIC = 'Public';

    public const string AUDIENCE_TAG_OWNERS = 'Owners';

    public const string AUDIENCE_TAG_STAFF = 'Staff';

    public const array PUBLIC_SYSTEM_SLUGS = [
        self::SLUG_MY,
        self::SLUG_EAC,
    ];

    public const array INTERNAL_SYSTEM_SLUGS = [
        self::SLUG_OWNERS,
        self::SLUG_STAFF,
    ];

    public const array SYSTEM_SLUGS = [
        self::SLUG_MY,
        self::SLUG_EAC,
        self::SLUG_OWNERS,
        self::SLUG_STAFF,
        self::SLUG_COMP,
    ];

    public const array STUDENT_HIDDEN_AUDIENCE_TAGS = [
        self::AUDIENCE_TAG_PUBLIC,
        self::AUDIENCE_TAG_OWNERS,
        self::AUDIENCE_TAG_STAFF,
    ];

    /**
     * @return array<string, array{name: string, background_color: ?string}>
     */
    public static function systemCalendarDefinitions(): array
    {
        return [
            self::SLUG_MY => [
                'name' => 'My Calendar',
                'background_color' => null,
            ],
            self::SLUG_EAC => [
                'name' => 'EAC Calendar',
                'background_color' => '#ff5733',
            ],
            self::SLUG_OWNERS => [
                'name' => 'Owners',
                'background_color' => '#2563eb',
            ],
            self::SLUG_STAFF => [
                'name' => 'Staff',
                'background_color' => '#16a34a',
            ],
            self::SLUG_COMP => [
                'name' => 'Comp Calendar',
                'background_color' => '#9333ea',
            ],
        ];
    }

    public static function isStudentAssignableAudienceTag(Tag $tag): bool
    {
        return $tag->type === self::AUDIENCE_TAG_TYPE
            && ! in_array($tag->name, self::STUDENT_HIDDEN_AUDIENCE_TAGS, true);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function isMyCalendar(): bool
    {
        return $this->slug === self::SLUG_MY;
    }

    public function isPublicSystemCalendar(): bool
    {
        return in_array($this->slug, self::PUBLIC_SYSTEM_SLUGS, true);
    }

    public function isSystemCalendar(): bool
    {
        return in_array($this->slug, self::SYSTEM_SLUGS, true);
    }

    public function isInternalSystemCalendar(): bool
    {
        return in_array($this->slug, self::INTERNAL_SYSTEM_SLUGS, true);
    }

    public function isCompetitionCalendar(): bool
    {
        return $this->slug === self::SLUG_COMP;
    }

    public function attachTag(string|Tag $tag, ?string $type = null): static
    {
        return $this->attachTagFromTrait($tag, $type);
    }

    public function attachTags(array|ArrayAccess|Tag $tags, ?string $type = null): static
    {
        return $this->attachTagsFromTrait($tags, $type);
    }

    public function syncTags(string|array|ArrayAccess $tags): static
    {
        return $this->syncTagsFromTrait($tags);
    }

    public function syncTagIds($ids, ?string $type = null, $detaching = true): void
    {
        if ($this->isPublicSystemCalendar() && $type === self::AUDIENCE_TAG_TYPE) {
            $ids = collect($ids)
                ->push(Tag::findOrCreate(self::AUDIENCE_TAG_PUBLIC, self::AUDIENCE_TAG_TYPE)->id)
                ->unique()
                ->values()
                ->all();
        }

        $this->syncTagIdsFromTrait($ids, $type, $detaching);
    }

    public function syncTagsWithType(array|ArrayAccess $tags, ?string $type = null): static
    {
        return $this->syncTagsWithTypeFromTrait($tags, $type);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $userAudienceTagIds = $user->calendarAudienceTagIds();
        $audienceTagIds = $user->studentCalendarAudienceTagIds();
        $allAudienceTagIds = $userAudienceTagIds
            ->merge($audienceTagIds)
            ->unique()
            ->values();
        $publicAudienceTagId = Tag::findFromString(self::AUDIENCE_TAG_PUBLIC, self::AUDIENCE_TAG_TYPE)?->id;
        $isCompetitionMember = app(CompetitionRosterService::class)->isCurrentMember($user);

        return $query->where(function (Builder $query) use ($allAudienceTagIds, $isCompetitionMember, $publicAudienceTagId, $userAudienceTagIds): void {
            $query->whereRaw('0 = 1');

            if ($publicAudienceTagId !== null) {
                $query->orWhereHas('tags', fn (Builder $query): Builder => $query
                    ->where('type', self::AUDIENCE_TAG_TYPE)
                    ->whereKey($publicAudienceTagId));
            }

            if ($isCompetitionMember) {
                $query->orWhere('slug', self::SLUG_COMP);
            }

            $query
                ->orWhere(function (Builder $query) use ($userAudienceTagIds): void {
                    $query
                        ->whereIn('slug', self::INTERNAL_SYSTEM_SLUGS)
                        ->whereHas('tags', fn (Builder $query): Builder => $query
                            ->where('type', self::AUDIENCE_TAG_TYPE)
                            ->whereIn('tags.id', $userAudienceTagIds));
                })
                ->orWhere(function (Builder $query) use ($allAudienceTagIds): void {
                    $query
                        ->whereNotIn('slug', self::SYSTEM_SLUGS)
                        ->whereHas('tags', fn (Builder $query): Builder => $query
                            ->where('type', self::AUDIENCE_TAG_TYPE)
                            ->whereIn('tags.id', $allAudienceTagIds));
                });
        });
    }

    public function scopeAssignableBy(Builder $query, User $user): Builder
    {
        if ($user->can('ViewAny:Calendar') || $user->can('Update:Calendar')) {
            return $query;
        }

        return $this->scopeVisibleTo($query, $user);
    }

    protected static function booted(): void
    {
        static::saving(function (Calendar $calendar): void {
            if (blank($calendar->slug)) {
                $calendar->slug = self::uniqueSlugForName($calendar->name, $calendar->id);
            }
        });

        static::deleting(fn (Calendar $calendar): bool => ! $calendar->isSystemCalendar());
    }

    private static function uniqueSlugForName(string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name) ?: 'calendar';
        $slug = $baseSlug;
        $suffix = 2;

        while (self::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
