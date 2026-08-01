<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\Token;

abstract class AbstractStudentCommunicationEmailType implements EmailTypeContract
{
    /**
     * @return list<Token>
     */
    protected function tokens(bool $includeStopLightColor = false): array
    {
        $tokens = [
            new Token('app.name', 'Application name', example: 'EAC'),
            new Token('communication.date', 'Communication date and time', example: 'July 31, 2026 7:00 PM EDT'),
            new Token('communication.note', 'Note entered by the sender', example: 'Today we discussed classroom expectations.'),
            new Token('communication.type', 'Communication type', example: 'First Aid'),
            new Token('event.name', 'Related event name, or “No event selected”', example: 'Ballet 2 Class'),
            new Token('event.starts_at', 'Related event start date and time', required: false, fallback: '', example: 'July 31, 2026 6:00 PM EDT'),
            new Token('event.ends_at', 'Related event end date and time', required: false, fallback: '', example: 'July 31, 2026 7:00 PM EDT'),
            new Token('event.course_name', 'Related event course name', required: false, fallback: '', example: 'Ballet 2'),
            new Token('teacher.first_name', 'Teacher first name', example: 'Jamie'),
            new Token('teacher.full_name', 'Teacher full name', example: 'Jamie Teacher'),
            new Token('teacher.email', 'Teacher email address', example: 'jamie@example.com'),
            new Token('student.first_name', 'Student first name', example: 'Alex'),
            new Token('student.full_name', 'Student full name', example: 'Alex Dancer'),
        ];

        if ($includeStopLightColor) {
            $tokens[] = new Token('stop_light.color', 'Selected stop-light color', example: 'Yellow');
        }

        return $tokens;
    }
}
