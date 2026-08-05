<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\StoreView;
use App\Support\MediaDisks;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;
use Throwable;

/**
 * @property-read string $fullName
 * @property-read string $full_name
 */
final class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar, HasMedia, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, InteractsWithMedia, Notifiable;

    public const array STAFF_ROLE_NAMES = ['owner', 'teacher'];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'store_view' => StoreView::List->value,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function fullName(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes): string => $attributes['first_name'].' '.$attributes['last_name']
        );
    }

    public function displayName(): string
    {
        return $this->fullName;
    }

    /**
     * Get the user's full name.
     */
    public function getFilamentName(): string
    {
        return $this->displayName();
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->getMediaUrl('avatars');
    }

    public function getStoreView(): StoreView
    {
        $storeView = $this->getAttribute('store_view');

        return $storeView instanceof StoreView ? $storeView : StoreView::List;
    }

    public function getStaffPhotoUrl(): ?string
    {
        return $this->getMediaUrl('staff-photo');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            return /* $this->hasVerifiedEmail() && */ $this->hasRole('teacher')
                || $this->getAllPermissions()->isNotEmpty();
        }

        return true;
    }

    public function isStaffMember(): bool
    {
        return $this->hasAnyRole(self::STAFF_ROLE_NAMES);
    }

    /** @return HasMany<Student, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /** @return BelongsToMany<CompetitionTeam, $this, CompetitionTeamStaff, 'pivot'> */
    public function competitionTeams(): BelongsToMany
    {
        return $this->belongsToMany(CompetitionTeam::class, 'competition_team_user')
            ->using(CompetitionTeamStaff::class)
            ->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasMany<CourseHold, $this> */
    public function courseHolds(): HasMany
    {
        return $this->hasMany(CourseHold::class);
    }

    public function events(): MorphMany
    {
        return $this->morphMany(EventAttendee::class, 'attendee');
    }

    public function excludedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_exclusions')
            ->withTimestamps();
    }

    /** @return HasMany<Event, $this> */
    public function substituteEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'substitute_teacher_id');
    }

    /** @return HasMany<EventSubstituteRequest, $this> */
    public function substituteRequests(): HasMany
    {
        return $this->hasMany(EventSubstituteRequest::class, 'teacher_id');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(FormUser::class);
    }

    public function purchasedCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'enrollments');
    }

    public function teachingCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_teacher', 'teacher_id', 'course_id')
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function paymentPlans(): HasManyThrough
    {
        return $this->hasManyThrough(PaymentPlan::class, Order::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function giftCardsPurchased(): HasMany
    {
        return $this->hasMany(GiftCard::class, 'purchased_by_user_id');
    }

    public function giftCardsRedeemed(): HasMany
    {
        return $this->hasMany(GiftCard::class, 'redeemed_by_user_id');
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** @return HasMany<CreditGrant, $this> */
    public function creditGrants(): HasMany
    {
        return $this->hasMany(CreditGrant::class);
    }

    public function creditBalance(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->availableStoreCreditBalance(),
        );
    }

    public function availableStoreCreditBalance(): int
    {
        return (int) $this->creditGrants()->available()->unrestricted()->sum('remaining_amount');
    }

    public function availableRestrictedCreditBalance(): int
    {
        return (int) $this->creditGrants()->available()->restricted()->sum('remaining_amount');
    }

    public function getRestrictedCreditForProduct(Product $product): int
    {
        return (int) $this->creditGrants()
            ->available()
            ->restricted()
            ->with('products')
            ->get()
            ->filter(fn (CreditGrant $grant): bool => $grant->appliesToProduct($product))
            ->sum('remaining_amount');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatars')
            ->useDisk(MediaDisks::private())
            ->singleFile();

        $this->addMediaCollection('staff-photo')
            ->useDisk(MediaDisks::private())
            ->singleFile();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'store_view' => StoreView::class,
            'table_preferences' => 'array',
        ];
    }

    private function getMediaUrl(string $collection): ?string
    {
        $media = $this->getFirstMedia($collection);

        if ($media === null) {
            return null;
        }

        if ($media->disk === MediaDisks::private()) {
            try {
                return $media->getTemporaryUrl(
                    now()->addMinutes((int) config('filament.temporary_file_url_expiry_minutes', 30))->endOfHour()
                );
            } catch (Throwable) {
                return $media->getUrl();
            }
        }

        return $media->getUrl();
    }

    // public function registerMediaConversions(?Media $media = null): void
    // {
    //     $this->addMediaConversion('thumb')
    //         ->width(150)
    //         ->height(150)
    //         ->sharpen(10)
    //         ->performOnCollections('avatars', 'staff-photo')
    //         ->nonQueued();
    // }
}
