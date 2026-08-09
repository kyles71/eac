<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Translation\PotentiallyTranslatedString;

final readonly class FutureDisplayDateTime implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $dateTime = Carbon::parse((string) $value, $this->displayTimezone());
        } catch (InvalidFormatException) {
            $fail('The :attribute must be a valid date and time.');

            return;
        }

        if ($dateTime->lte(now())) {
            $fail('The :attribute must be a date and time in the future.');
        }
    }

    private function displayTimezone(): string
    {
        $timezone = config('app.display_timezone', config('app.timezone', 'UTC'));

        return is_string($timezone) ? $timezone : 'UTC';
    }
}
