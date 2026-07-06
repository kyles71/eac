<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormAnswerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $form_id
 * @property string $key
 * @property FormAnswerType $answer_type
 * @property string|null $mapping
 * @property string|null $label
 * @property string|null $block_key
 * @property string|null $sub_key
 */
final class FormField extends Model
{
    /** @use HasFactory<\Database\Factories\FormFieldFactory> */
    use HasFactory;

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return HasMany<FormAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
    }

    protected static function booted(): void
    {
        self::updating(function (FormField $field): void {
            if ($field->isDirty(['form_id', 'key', 'answer_type', 'mapping', 'block_key', 'sub_key'])) {
                throw new LogicException('Stable form field identity, answer type, and mapping are immutable.');
            }
        });

        self::deleting(function (FormField $field): void {
            if ($field->answers()->exists()) {
                throw new LogicException('Form fields with response history cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'form_id' => 'integer',
            'answer_type' => FormAnswerType::class,
        ];
    }
}
