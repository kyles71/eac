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
            names: ['en' => 'Student Stoplight Note'],
            description: 'Sent by staff when recording a red, yellow, or green Stoplight note for a student.',
            category: 'administrative',
            subjects: ['en' => '{{ stop_light.color }} Stoplight Note for {{ student.first_name }} - {{ event.context_name }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello,</p>
                <p>A <strong>{{ stop_light.color }}</strong> Stoplight note has been recorded for {{ student.full_name }}.</p>
                <p><strong>Date:</strong> {{ communication.date }}</p>
                <p><strong>Event:</strong> {{ event.name }}</p>
                <p><strong>Teacher:</strong> {{ teacher.full_name }}</p>
                <p style="white-space: pre-line">{{ communication.note }}</p>
                HTML],
            tokens: $this->tokens(includeStopLightColor: true),
        );
    }
}
