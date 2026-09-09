<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Event;
use App\Models\User;

final readonly class TeacherScheduleProposal
{
    /**
     * @param  list<User>  $teachers
     */
    public function __construct(
        public Event $event,
        public array $teachers,
    ) {}
}
