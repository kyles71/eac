<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormAnswerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $form_response_id
 * @property int $form_field_id
 * @property int|null $form_answer_group_id
 * @property string|null $value_string
 * @property string|null $value_text
 * @property int|null $value_integer
 * @property string|null $value_decimal
 * @property bool|null $value_boolean
 * @property \Illuminate\Support\Carbon|null $value_date
 * @property \Illuminate\Support\Carbon|null $value_datetime
 * @property-read FormField $field
 */
final class FormAnswer extends Model
{
    /** @use HasFactory<\Database\Factories\FormAnswerFactory> */
    use HasFactory;

    /** @return BelongsTo<FormResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    /** @return BelongsTo<FormField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /** @return BelongsTo<FormAnswerGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(FormAnswerGroup::class, 'form_answer_group_id');
    }

    public function value(): mixed
    {
        return match ($this->field->answer_type) {
            FormAnswerType::String => $this->value_string,
            FormAnswerType::Text => $this->value_text,
            FormAnswerType::Integer => $this->value_integer,
            FormAnswerType::Decimal => $this->value_decimal,
            FormAnswerType::Boolean => $this->value_boolean,
            FormAnswerType::Date => $this->value_date?->toDateString(),
            FormAnswerType::DateTime => $this->value_datetime?->toDateTimeString(),
        };
    }

    protected function casts(): array
    {
        return [
            'form_response_id' => 'integer',
            'form_field_id' => 'integer',
            'form_answer_group_id' => 'integer',
            'value_integer' => 'integer',
            'value_decimal' => 'decimal:4',
            'value_boolean' => 'boolean',
            'value_date' => 'date',
            'value_datetime' => 'datetime',
        ];
    }
}
