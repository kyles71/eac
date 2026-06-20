@php
    $startsAt = $event->start_time?->copy()->setTimezone($displayTimezone);
    $endsAt = $event->end_time?->copy()->setTimezone($displayTimezone);
@endphp

<table role="presentation" style="width: 100%; border-collapse: collapse; margin: 16px 0;">
    <tr>
        <th scope="row" style="padding: 6px 8px; text-align: left;">Event</th>
        <td style="padding: 6px 8px;">{{ $event->name }}</td>
    </tr>
    @if ($event->course !== null)
        <tr>
            <th scope="row" style="padding: 6px 8px; text-align: left;">Course</th>
            <td style="padding: 6px 8px;">{{ $event->course->name }}</td>
        </tr>
    @endif
    @if ($event->calendar !== null)
        <tr>
            <th scope="row" style="padding: 6px 8px; text-align: left;">Calendar</th>
            <td style="padding: 6px 8px;">{{ $event->calendar->name }}</td>
        </tr>
    @endif
    @if ($startsAt !== null)
        <tr>
            <th scope="row" style="padding: 6px 8px; text-align: left;">Starts</th>
            <td style="padding: 6px 8px;">{{ $startsAt->format('F j, Y g:i A T') }}</td>
        </tr>
    @endif
    @if ($endsAt !== null)
        <tr>
            <th scope="row" style="padding: 6px 8px; text-align: left;">Ends</th>
            <td style="padding: 6px 8px;">{{ $endsAt->format('F j, Y g:i A T') }}</td>
        </tr>
    @endif
</table>
