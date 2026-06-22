<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CalendarAccess;
use App\Services\CompetitionRosterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Calendar extends Model
{
    /** @use HasFactory<\Database\Factories\CalendarFactory> */
    use HasFactory;

    public const string SLUG_MY = 'my';

    public const string SLUG_EAC = 'eac';

    public const string SLUG_OWNERS = 'owners';

    public const string SLUG_STAFF = 'staff';

    public const string SLUG_COMP = 'comp';

    public const array PUBLIC_SYSTEM_SLUGS = [
        self::SLUG_MY,
        self::SLUG_EAC,
    ];

    public const array SYSTEM_SLUGS = [
        self::SLUG_MY,
        self::SLUG_EAC,
        self::SLUG_OWNERS,
        self::SLUG_STAFF,
        self::SLUG_COMP,
    ];

    protected $casts = [
        'id' => 'integer',
        'access' => CalendarAccess::class,
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

    /** @return HasMany<CalendarAudience, $this> */
    public function audiences(): HasMany
    {
        return $this->hasMany(CalendarAudience::class);
    }

    /** @return HasMany<Event, $this> */
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

    public function isCompetitionCalendar(): bool
    {
        return $this->slug === self::SLUG_COMP;
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $studentIds = $user->students()->pluck('students.id');
        $courseIds = Course::query()
            ->where(function (Builder $query) use ($studentIds, $user): void {
                $query->whereHas('teachers', fn (Builder $query): Builder => $query->whereKey($user->id));

                if ($studentIds->isNotEmpty()) {
                    $query->orWhereHas('students', fn (Builder $query): Builder => $query->whereIn('students.id', $studentIds));
                }
            })
            ->pluck('courses.id');
        $isOwner = $user->hasAnyRole(['owner', 'super_admin']);
        $isStaff = $isOwner || $user->hasRole('teacher');
        $isCompetitionMember = app(CompetitionRosterService::class)->isCurrentMember($user);
        $userMorphClass = $user->getMorphClass();
        $studentMorphClass = (new Student())->getMorphClass();
        $courseMorphClass = (new Course())->getMorphClass();

        return $query->where(function (Builder $query) use ($courseIds, $courseMorphClass, $isCompetitionMember, $isOwner, $isStaff, $studentIds, $studentMorphClass, $user, $userMorphClass): void {
            $query->whereIn('slug', self::PUBLIC_SYSTEM_SLUGS);

            if ($isOwner) {
                $query->orWhere('slug', self::SLUG_OWNERS);
            }

            if ($isStaff) {
                $query->orWhere('slug', self::SLUG_STAFF);
            }

            if ($isCompetitionMember) {
                $query->orWhere('slug', self::SLUG_COMP);
            }

            $query->orWhere(function (Builder $query) use ($courseIds, $courseMorphClass, $studentIds, $studentMorphClass, $user, $userMorphClass): void {
                $query
                    ->whereNotIn('slug', self::SYSTEM_SLUGS)
                    ->where(function (Builder $query) use ($courseIds, $courseMorphClass, $studentIds, $studentMorphClass, $user, $userMorphClass): void {
                        $query
                            ->where('access', CalendarAccess::Public->value)
                            ->orWhere(function (Builder $query) use ($courseIds, $courseMorphClass, $studentIds, $studentMorphClass, $user, $userMorphClass): void {
                                $query
                                    ->where('access', CalendarAccess::Restricted->value)
                                    ->whereHas('audiences', function (Builder $query) use ($courseIds, $courseMorphClass, $studentIds, $studentMorphClass, $user, $userMorphClass): void {
                                        $query->where(function (Builder $query) use ($courseIds, $courseMorphClass, $studentIds, $studentMorphClass, $user, $userMorphClass): void {
                                            $query->where(function (Builder $query) use ($user, $userMorphClass): void {
                                                $query
                                                    ->where('audience_type', $userMorphClass)
                                                    ->where('audience_id', $user->id);
                                            });

                                            if ($studentIds->isNotEmpty()) {
                                                $query->orWhere(function (Builder $query) use ($studentIds, $studentMorphClass): void {
                                                    $query
                                                        ->where('audience_type', $studentMorphClass)
                                                        ->whereIn('audience_id', $studentIds);
                                                });
                                            }

                                            if ($courseIds->isNotEmpty()) {
                                                $query->orWhere(function (Builder $query) use ($courseIds, $courseMorphClass): void {
                                                    $query
                                                        ->where('audience_type', $courseMorphClass)
                                                        ->whereIn('audience_id', $courseIds);
                                                });
                                            }
                                        });
                                    });
                            });
                    });
            });
        });
    }

    public function scopeAssignableBy(Builder $query, User $user): Builder
    {
        return $this->scopeVisibleTo($query, $user);
    }

    /** @return Builder<User> */
    public function usersWithAccess(): Builder
    {
        $query = User::query();

        if ($this->isPublicSystemCalendar() || (! $this->isSystemCalendar() && $this->access === CalendarAccess::Public)) {
            return $query;
        }

        if ($this->slug === self::SLUG_OWNERS) {
            return $query->whereHas('roles', fn (Builder $query): Builder => $query->whereIn('name', ['owner', 'super_admin']));
        }

        if ($this->slug === self::SLUG_STAFF) {
            return $query->whereHas('roles', fn (Builder $query): Builder => $query->whereIn('name', ['teacher', 'owner', 'super_admin']));
        }

        if ($this->isCompetitionCalendar()) {
            return app(CompetitionRosterService::class)->applyCurrentAccountScope($query);
        }

        if ($this->isSystemCalendar() || $this->access !== CalendarAccess::Restricted) {
            return $query->whereRaw('0 = 1');
        }

        $userMorphClass = (new User())->getMorphClass();
        $studentMorphClass = (new Student())->getMorphClass();
        $courseMorphClass = (new Course())->getMorphClass();
        $directUserIds = $this->audiences()->where('audience_type', $userMorphClass)->pluck('audience_id');
        $studentIds = $this->audiences()->where('audience_type', $studentMorphClass)->pluck('audience_id');
        $courseIds = $this->audiences()->where('audience_type', $courseMorphClass)->pluck('audience_id');

        return $query->where(function (Builder $query) use ($courseIds, $directUserIds, $studentIds): void {
            $query->whereIn('users.id', $directUserIds);

            if ($studentIds->isNotEmpty()) {
                $query->orWhereHas('students', fn (Builder $query): Builder => $query->whereIn('students.id', $studentIds));
            }

            if ($courseIds->isNotEmpty()) {
                $query
                    ->orWhereHas('teachingCourses', fn (Builder $query): Builder => $query->whereIn('courses.id', $courseIds))
                    ->orWhereHas('students.courses', fn (Builder $query): Builder => $query->whereIn('courses.id', $courseIds));
            }
        });
    }

    protected static function booted(): void
    {
        self::saving(function (Calendar $calendar): void {
            if (blank($calendar->slug)) {
                $calendar->slug = self::uniqueSlugForName($calendar->name, $calendar->id);
            }

            if ($calendar->isSystemCalendar()) {
                $calendar->access = null;
            } elseif ($calendar->access === null) {
                $calendar->access = CalendarAccess::Restricted;
            }
        });

        self::deleting(fn (Calendar $calendar): bool => ! $calendar->isSystemCalendar());

        self::saved(function (Calendar $calendar): void {
            if ($calendar->isSystemCalendar() || $calendar->access === CalendarAccess::Public) {
                $calendar->audiences()->delete();
            }
        });
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
