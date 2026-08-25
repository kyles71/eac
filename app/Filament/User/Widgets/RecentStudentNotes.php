<?php

declare(strict_types=1);

namespace App\Filament\User\Widgets;

use App\Filament\User\Pages\Messages;
use App\Models\User;
use App\Notifications\StudentNoteSent;
use Filament\Widgets\Widget;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;

final class RecentStudentNotes extends Widget
{
    protected string $view = 'filament.user.widgets.recent-student-notes';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->unreadNotifications()
                ->where('type', StudentNoteSent::class)
                ->exists();
    }

    /** @return DatabaseNotificationCollection<int, DatabaseNotification> */
    public function notes(): DatabaseNotificationCollection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new DatabaseNotificationCollection();
        }

        return $user->unreadNotifications()
            ->where('type', StudentNoteSent::class)
            ->latest()
            ->get();
    }

    public function viewNote(string $notificationId): void
    {
        $this->markAsRead($notificationId);
        $this->redirect(Messages::getUrl());
    }

    public function dismiss(string $notificationId): void
    {
        $this->markAsRead($notificationId);
    }

    private function markAsRead(string $notificationId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $user->unreadNotifications()
            ->where('type', StudentNoteSent::class)
            ->whereKey($notificationId)
            ->first()
            ?->markAsRead();
    }
}
