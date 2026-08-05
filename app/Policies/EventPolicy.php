<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class EventPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Event');
    }

    public function view(AuthUser $authUser, Event $event): bool
    {
        return $authUser->can('View:Event');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Event');
    }

    public function update(AuthUser $authUser, Event $event): bool
    {
        return $authUser->can('Update:Event');
    }

    public function viewSubstituteDetails(AuthUser $authUser, Event $event): bool
    {
        return $authUser instanceof User && $event->substitute_teacher_id === $authUser->id;
    }

    public function recordSubstituteAttendance(AuthUser $authUser, Event $event): bool
    {
        return $this->viewSubstituteDetails($authUser, $event) && ! $event->isCancelled();
    }

    public function requestSubstituteRelease(AuthUser $authUser, Event $event): bool
    {
        return $this->viewSubstituteDetails($authUser, $event)
            && ! $event->isCancelled()
            && ! $event->isCompletedAt();
    }

    public function cancel(AuthUser $authUser, Event $event): bool
    {
        return $event->canBeCancelledAt() && $authUser->can('Cancel:Event');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Event');
    }
}
