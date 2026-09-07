<?php

declare(strict_types=1);

namespace App\Services\Boards;

use App\Models\BoardItem;
use App\Models\BoardItemSubscription;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BoardItemNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class BoardNotificationService
{
    public function followAutomatically(BoardItem $item, User $user): void
    {
        BoardItemSubscription::query()->firstOrCreate([
            'board_item_id' => $item->id,
            'user_id' => $user->id,
        ], [
            'muted_at' => null,
        ]);
    }

    public function watch(BoardItem $item, User $user): void
    {
        BoardItemSubscription::query()->updateOrCreate([
            'board_item_id' => $item->id,
            'user_id' => $user->id,
        ], [
            'muted_at' => null,
        ]);
    }

    public function unwatch(BoardItem $item, User $user): void
    {
        BoardItemSubscription::query()->updateOrCreate([
            'board_item_id' => $item->id,
            'user_id' => $user->id,
        ], [
            'muted_at' => now(),
        ]);
    }

    public function notifyNewSubmission(BoardItem $item, User $actor): void
    {
        $recipients = $item->board->managers()->get()
            ->merge(User::query()->role([Role::OWNER, Role::SUPER_ADMIN])->get());

        $this->notify(
            $item,
            $actor,
            $recipients,
            'New board submission',
            $actor->getFilamentName().' added “'.$item->title.'” to '.$item->board->name.'.',
        );
    }

    /** @param list<int> $additionalUserIds */
    public function notifyFollowers(BoardItem $item, User $actor, string $title, string $body, array $additionalUserIds = []): void
    {
        $recipients = User::query()
            ->where(function (Builder $query) use ($item, $additionalUserIds): void {
                $query->whereHas('boardItemSubscriptions', fn (Builder $query): Builder => $query
                    ->where('board_item_id', $item->id)
                    ->whereNull('muted_at'));

                if ($additionalUserIds !== []) {
                    $query->orWhereIn('users.id', $additionalUserIds);
                }
            })
            ->get();

        $this->notify($item, $actor, $recipients, $title, $body);
    }

    /** @param Collection<int, User> $recipients */
    private function notify(BoardItem $item, User $actor, Collection $recipients, string $title, string $body): void
    {
        $recipients
            ->unique('id')
            ->reject(fn (User $user): bool => $user->is($actor))
            ->each(fn (User $user) => $user->notify(new BoardItemNotification(
                itemId: $item->id,
                title: $title,
                body: $body,
            )));
    }
}
