<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EnrollmentEmailAudience: string implements HasLabel
{
    case UserAccounts = 'user_accounts';
    case StudentEmails = 'student_emails';

    public function getLabel(): string
    {
        return match ($this) {
            self::UserAccounts => 'User Accounts',
            self::StudentEmails => 'Student Emails',
        };
    }
}
