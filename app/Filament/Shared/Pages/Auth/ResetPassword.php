<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Auth;

use App\Support\PasswordRequirements;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

final class ResetPassword extends BaseResetPassword
{
    protected function getPasswordFormComponent(): Component
    {
        $component = parent::getPasswordFormComponent();

        if (! $component instanceof TextInput) {
            return $component;
        }

        return PasswordRequirements::withFeedback($component)
            ->showAllValidationMessages();
    }
}
