<div>
    <p><strong>{{ $series->student->displayName() }}</strong><br>{{ $series->course->name }}</p>

    <ul>
        @foreach ($charges as $charge)
            <li>
                {{ $charge->event->start_time?->timezone(config('app.display_timezone', config('app.timezone')))->format('F j, Y \a\t g:i A') }}
                — {{ format_money($charge->amount) }}
                ({{ $charge->status->getLabel() }})
            </li>
        @endforeach
    </ul>

    <p><a href="{{ $paymentUrl }}">Review and pay in the portal</a></p>
</div>
