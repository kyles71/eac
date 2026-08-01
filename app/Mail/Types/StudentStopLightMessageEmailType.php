<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Data\EmailTypeDefinition;

final class StudentStopLightMessageEmailType extends AbstractStudentCommunicationEmailType
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'student-stop-light-message',
            names: ['en' => 'Student Stop Light Message'],
            description: 'Sent by staff when recording a red, yellow, or green stop-light communication for a student.',
            category: 'administrative',
            subjects: ['en' => '{{ stop_light.color }} stop light message for {{ student.full_name }} — {{ communication.date }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello,</p>
                <p>A <strong>{{ stop_light.color }}</strong> stop light message has been recorded for {{ student.full_name }}.</p>
                <p><strong>Date:</strong> {{ communication.date }}</p>
                <p><strong>Event:</strong> {{ event.name }}</p>
                <p><strong>Teacher:</strong> {{ teacher.full_name }}</p>
                <p style="white-space: pre-line">{{ communication.note }}</p>
                HTML],
            tokens: $this->tokens(includeStopLightColor: true),
        );
    }
}
