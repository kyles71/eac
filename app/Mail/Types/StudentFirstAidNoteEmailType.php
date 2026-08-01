<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Data\EmailTypeDefinition;

final class StudentFirstAidNoteEmailType extends AbstractStudentCommunicationEmailType
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'student-first-aid-note',
            names: ['en' => 'Student First Aid Note'],
            description: 'Sent by staff when recording a first aid communication for a student.',
            category: 'administrative',
            subjects: ['en' => 'First aid note for {{ student.full_name }} — {{ communication.date }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello,</p>
                <p>A first aid note has been recorded for {{ student.full_name }}.</p>
                <p><strong>Date:</strong> {{ communication.date }}</p>
                <p><strong>Event:</strong> {{ event.name }}</p>
                <p><strong>Teacher:</strong> {{ teacher.full_name }}</p>
                <p style="white-space: pre-line">{{ communication.note }}</p>
                HTML],
            tokens: $this->tokens(),
        );
    }
}
