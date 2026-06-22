<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\CreditTransactionType;
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
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Get the user's full name.
     */
    public function getFilamentName(): string
    {
        // @phpstan-ignore-next-line property.notFound
        return $this->fullName;
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
            return /* $this->hasVerifiedEmail() && */ $this->getAllPermissions()->isNotEmpty();
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

    public function events(): MorphMany
    {
        return $this->morphMany(EventAttendee::class, 'attendee');
    }

    public function excludedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_exclusions')
            ->withTimestamps();
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

    /** @return HasMany<RestrictedCredit, $this> */
    public function restrictedCredits(): HasMany
    {
        return $this->hasMany(RestrictedCredit::class);
    }

    /**
     * Get the total restricted credit balance applicable to a given product.
     */
    public function getRestrictedCreditForProduct(Product $product): int
    {
        return $this->restrictedCredits()
            ->where('balance', '>', 0)
            ->with('giftCardType.products')
            ->get()
            ->filter(fn (RestrictedCredit $rc): bool => $rc->giftCardType->appliesToProduct($product))
            ->sum('balance');
    }

    /**
     * Debit restricted credits applicable to a product (FIFO by oldest).
     * Returns the actual amount debited.
     */
    public function applyRestrictedCredit(Product $product, int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $applicableCredits = $this->restrictedCredits()
            ->where('balance', '>', 0)
            ->with('giftCardType.products')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (RestrictedCredit $rc): bool => $rc->giftCardType->appliesToProduct($product));

        $totalDebited = 0;

        /** @var RestrictedCredit $restrictedCredit */
        foreach ($applicableCredits as $restrictedCredit) {
            if ($totalDebited >= $amount) {
                break;
            }

            $debit = min($restrictedCredit->balance, $amount - $totalDebited);
            $restrictedCredit->update(['balance' => $restrictedCredit->balance - $debit]);
            $totalDebited += $debit;
        }

        return $totalDebited;
    }

    /**
     * Reverse restricted credits that were debited for an order.
     * Re-credits the amount back to the most recent applicable restricted credit records (reverse FIFO).
     */
    public function reverseRestrictedCredit(int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $applicableCredits = $this->restrictedCredits()
            ->where('balance', '>=', 0)
            ->orderByDesc('created_at')
            ->get();

        $totalCredited = 0;

        /** @var RestrictedCredit $restrictedCredit */
        foreach ($applicableCredits as $restrictedCredit) {
            if ($totalCredited >= $amount) {
                break;
            }

            $credit = min($amount - $totalCredited, PHP_INT_MAX);
            $restrictedCredit->update(['balance' => $restrictedCredit->balance + $credit]);
            $totalCredited += $credit;
        }

        return $totalCredited;
    }

    /**
     * Adjust the user's credit balance and record a transaction.
     *
     * @param  int  $amount  Positive to add credit, negative to debit
     */
    public function adjustCredit(int $amount, CreditTransactionType $type, ?Model $reference = null, ?string $description = null): CreditTransaction
    {
        if ($amount !== 0) {
            self::query()
                ->whereKey($this->getKey())
                ->increment('credit_balance', $amount);

            $this->refresh();
        }

        /** @var CreditTransaction */
        return $this->creditTransactions()->create([
            'amount' => $amount,
            'type' => $type,
            'reference_type' => $reference !== null ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->getKey(),
            'description' => $description,
        ]);
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
            'password' => 'hashed',
            'credit_balance' => 'integer',
            'store_view' => StoreView::class,
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
