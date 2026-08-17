<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class EventPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $authUser): bool
    {
        return $authUser->can('ViewAny:Event');
    }

    public function view(User $authUser, Event $event): bool
    {
        return $authUser->can('View:Event') && $this->canAccessPrivateEvent($authUser, $event);
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Event');
    }

    public function update(User $authUser, Event $event): bool
    {
        return $authUser->can('Update:Event') && $this->canManagePrivateEvent($authUser, $event);
    }

    public function cancel(User $authUser, Event $event): bool
    {
        return $event->canBeCancelledAt()
            && ! $event->recurringPrivateLessonCharge()->exists()
            && $authUser->can('Cancel:Event')
            && $this->canManagePrivateEvent($authUser, $event);
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:Event');
    }

    public function delete(User $authUser, Event $event): bool
    {
        return $authUser->can('DeleteAny:Event') && $this->canManagePrivateEvent($authUser, $event);
    }

    private function canAccessPrivateEvent(User $authUser, Event $event): bool
    {
        $event->loadMissing('course.recurringPrivateLesson');

        if ($event->course?->recurringPrivateLesson !== null) {
            return $authUser->hasAnyRole(['teacher', 'owner', 'super_admin'])
                || $event->course->recurringPrivateLesson->user_id === $authUser->id;
        }

        return $event->course === null
            || ! $event->course->is_private
            || $authUser->hasAnyRole(['owner', 'super_admin'])
            || $event->course->teachers()->whereKey($authUser->id)->exists();
    }

    private function canManagePrivateEvent(User $authUser, Event $event): bool
    {
        $event->loadMissing('course');

        return $event->course === null
            || ! $event->course->is_private
            || $authUser->hasAnyRole(['owner', 'super_admin']);
    }
}
