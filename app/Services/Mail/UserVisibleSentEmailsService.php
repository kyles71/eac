<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\User;
use FinityLabs\FinMail\Enums\EmailStatus;
use Illuminate\Database\Eloquent\Builder;
use Kyle\FilamentMailManager\Models\ManagedSentEmail;

final readonly class UserVisibleSentEmailsService
{
    /**
     * @return Builder<ManagedSentEmail>
     */
    public function query(User $user): Builder
    {
        return ManagedSentEmail::query()
            ->where('status', EmailStatus::Sent)
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->whereJsonContains('to', $user->email)
                    ->orWhereJsonContains('cc', $user->email);
            })
            ->with('template')
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
