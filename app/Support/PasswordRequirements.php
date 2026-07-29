<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\View;
use Illuminate\Validation\Rules\Password;

final class PasswordRequirements
{
    public const int MAX_LENGTH = 255;

    public const int MIN_LENGTH = 8;

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)
            ->max(self::MAX_LENGTH)
            ->uncompromised();
    }

    public static function withFeedback(TextInput $input): TextInput
    {
        return $input->belowContent(
            View::make('filament.shared.password-requirements')
                ->viewData([
                    'maximumLength' => self::MAX_LENGTH,
                    'minimumLength' => self::MIN_LENGTH,
                ]),
        );
    }
}
