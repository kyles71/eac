<table role="presentation" style="width: 100%; border-collapse: collapse; margin: 16px 0;">
    <tr>
        <th align="left" style="padding: 6px 8px;">Event</th>
        <td style="padding: 6px 8px;">{{ $event->name }}</td>
    </tr>
    @if ($event->course !== null)
        <tr>
            <th align="left" style="padding: 6px 8px;">Course</th>
            <td style="padding: 6px 8px;">{{ $event->course->name }}</td>
        </tr>
    @endif
    @if ($event->calendar !== null)
        <tr>
            <th align="left" style="padding: 6px 8px;">Calendar</th>
            <td style="padding: 6px 8px;">{{ $event->calendar->name }}</td>
        </tr>
    @endif
    @if ($event->start_time !== null)
        <tr>
            <th align="left" style="padding: 6px 8px;">Starts</th>
            <td style="padding: 6px 8px;">{{ $event->start_time->copy()->timezone($displayTimezone)->format('M j, Y g:i A T') }}</td>
        </tr>
    @endif
    @if ($event->end_time !== null)
        <tr>
            <th align="left" style="padding: 6px 8px;">Ends</th>
            <td style="padding: 6px 8px;">{{ $event->end_time->copy()->timezone($displayTimezone)->format('M j, Y g:i A T') }}</td>
        </tr>
    @endif
</table>
