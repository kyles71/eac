<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;

final class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

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
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

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
        /* TODO: Please implement your own logic here. */
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

    public function purchasedCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'enrollments');
    }
}
