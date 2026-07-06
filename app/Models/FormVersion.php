<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormVersionStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $form_id
 * @property int $version
 * @property FormVersionStatus $status
 * @property array<int, array<string, mixed>> $schema
 * @property bool $requires_signature
 * @property \Carbon\CarbonInterface|null $valid_until
 * @property int|null $published_by_id
 * @property \Carbon\CarbonInterface|null $published_at
 * @property-read Form $form
 */
final class FormVersion extends Model
{
    /** @use HasFactory<\Database\Factories\FormVersionFactory> */
    use HasFactory;

    public static function applyActiveConstraint(Builder $query): void
    {
        $query
            ->where('status', FormVersionStatus::Published)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>', now());
            });
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    /** @return HasMany<FormAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(FormAssignment::class);
    }

    /** @return HasMany<FormResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(FormResponse::class);
    }

    public function versionLabel(): string
    {
        return "Version {$this->version}";
    }

    public function isPublished(): bool
    {
        return $this->status === FormVersionStatus::Published;
    }

    public function isDraft(): bool
    {
        return $this->getRawOriginal('status') === FormVersionStatus::Draft->value;
    }

    protected static function booted(): void
    {
        self::creating(function (FormVersion $version): void {
            if (
                $version->status === FormVersionStatus::Draft
                && FormVersion::query()
                    ->where('form_id', $version->form_id)
                    ->where('status', FormVersionStatus::Draft)
                    ->exists()
            ) {
                throw new LogicException('A form may only have one draft version.');
            }
        });

        self::updating(function (FormVersion $version): void {
            if (
                $version->getRawOriginal('status') === FormVersionStatus::Published->value
                && $version->isDirty([
                    'form_id',
                    'version',
                    'status',
                    'schema',
                    'requires_signature',
                    'valid_until',
                    'published_by_id',
                    'published_at',
                ])
            ) {
                throw new LogicException('Published form versions are immutable. Create a new draft instead.');
            }
        });

        self::deleting(function (FormVersion $version): void {
            if ($version->isPublished()) {
                throw new LogicException('Published form versions cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'form_id' => 'integer',
            'version' => 'integer',
            'status' => FormVersionStatus::class,
            'schema' => 'array',
            'requires_signature' => 'boolean',
            'valid_until' => 'datetime',
            'published_by_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        self::applyActiveConstraint($query);
    }
}
