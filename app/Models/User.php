<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\CreditTransactionType;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;

final class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
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

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            return true || $this->hasVerifiedEmail();
        }

        return true; // str_ends_with($this->email, '@larament.test');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function events(): MorphMany
    {
        return $this->morphMany(EventAttendee::class, 'attendee');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(FormUser::class);
    }

    public function purchasedCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'enrollments');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
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

    /**
     * Adjust the user's credit balance and record a transaction.
     *
     * @param  int  $amount  Positive to add credit, negative to debit
     */
    public function adjustCredit(int $amount, CreditTransactionType $type, ?Model $reference = null, ?string $description = null): CreditTransaction
    {
        $this->increment('credit_balance', $amount);

        /** @var CreditTransaction */
        return $this->creditTransactions()->create([
            'amount' => $amount,
            'type' => $type,
            'reference_type' => $reference !== null ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->getKey(),
            'description' => $description,
        ]);
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
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }
}
