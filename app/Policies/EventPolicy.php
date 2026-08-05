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
        return $authUser->can('View:Event')
            && $event->isAccessibleToAdminUser($authUser);
    }

    public function create(User $authUser): bool
    {
        return $authUser->can('Create:Event');
    }

    public function update(User $authUser, Event $event): bool
    {
        return $authUser->can('Update:Event')
            && $event->isAccessibleToAdminUser($authUser);
    }

    public function viewSubstituteDetails(User $authUser, Event $event): bool
    {
        return $authUser instanceof User && $event->substitute_teacher_id === $authUser->id;
    }

    public function recordSubstituteAttendance(User $authUser, Event $event): bool
    {
        return $this->viewSubstituteDetails($authUser, $event) && ! $event->isCancelled();
    }

    public function requestSubstituteRelease(User $authUser, Event $event): bool
    {
        return $this->viewSubstituteDetails($authUser, $event)
            && ! $event->isCancelled()
            && ! $event->isCompletedAt();
    }

    public function updateAttendance(User $authUser, Event $event): bool
    {
        if (! $this->update($authUser, $event)) {
            return false;
        }

        if ($event->course_id === null) {
            return true;
        }

        return ! $event->course()->firstOrFail()->hasConcluded();
    }

    public function cancel(User $authUser, Event $event): bool
    {
        return $event->canBeCancelledAt()
            && $authUser->can('Cancel:Event')
            && $event->isAccessibleToAdminUser($authUser);
    }

    public function deleteAny(User $authUser): bool
    {
        return $authUser->can('DeleteAny:Event');
    }
}
