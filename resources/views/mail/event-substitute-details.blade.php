<p>
    <strong>{{ $event->name }}</strong><br>
    @if ($event->course)
        Course: {{ $event->course->name }}<br>
    @endif
    @if ($event->calendar)
        Calendar: {{ $event->calendar->name }}<br>
    @endif
    @if ($event->start_time)
        {{ $event->start_time->timezone($displayTimezone)->format('F j, Y g:i A T') }}
    @endif
    @if ($event->end_time)
        –{{ $event->end_time->timezone($displayTimezone)->format('g:i A T') }}
    @endif
</p>
