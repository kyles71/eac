<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $form_id
 * @property int $form_version_id
 * @property int $form_response_id
 * @property string $block_key
 * @property int $position
 * @property-read \Illuminate\Database\Eloquent\Collection<int, FormAnswer> $answers
 */
final class FormAnswerGroup extends Model
{
    /** @use HasFactory<\Database\Factories\FormAnswerGroupFactory> */
    use HasFactory;

    /** @return BelongsTo<FormResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** @return HasMany<FormAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
    }

    protected function casts(): array
    {
        return [
            'form_id' => 'integer',
            'form_version_id' => 'integer',
            'form_response_id' => 'integer',
            'position' => 'integer',
        ];
    }
}
