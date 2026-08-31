<table role="presentation" style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr>
            <th style="text-align: left; padding: 8px; border-bottom: 1px solid #d1d5db;">Lesson</th>
            <th style="text-align: left; padding: 8px; border-bottom: 1px solid #d1d5db;">Dancer</th>
            <th style="text-align: left; padding: 8px; border-bottom: 1px solid #d1d5db;">Style</th>
            <th style="text-align: left; padding: 8px; border-bottom: 1px solid #d1d5db;">Teacher</th>
            <th style="text-align: right; padding: 8px; border-bottom: 1px solid #d1d5db;">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($charges as $charge)
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #e5e7eb;">
                    {{ $charge->event->start_time?->timezone(config('app.display_timezone', config('app.timezone')))->format('M j, Y g:i A') }}
                </td>
                <td style="padding: 8px; border-bottom: 1px solid #e5e7eb;">{{ $charge->recurringPrivateLesson->student->displayName() }}</td>
                <td style="padding: 8px; border-bottom: 1px solid #e5e7eb;">{{ $charge->recurringPrivateLesson->course->name }}</td>
                <td style="padding: 8px; border-bottom: 1px solid #e5e7eb;">
                    {{ $charge->recurringPrivateLesson->course->teachers->map->displayName()->join(', ') }}
                </td>
                <td style="text-align: right; padding: 8px; border-bottom: 1px solid #e5e7eb;">{{ format_money($charge->amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
