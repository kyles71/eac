<div>
    <table role="presentation" style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr>
            <th align="left" style="padding: 6px 8px;">Event</th>
            <td style="padding: 6px 8px;">{{ $event->name }}</td>
        </tr>
        @if ($event->start_time !== null)
            <tr>
                <th align="left" style="padding: 6px 8px;">Date</th>
                <td style="padding: 6px 8px;">{{ $event->start_time->copy()->timezone($displayTimezone)->format('F j, Y') }}</td>
            </tr>
            <tr>
                <th align="left" style="padding: 6px 8px;">Starts</th>
                <td style="padding: 6px 8px;">{{ $event->start_time->copy()->timezone($displayTimezone)->format('g:i A T') }}</td>
            </tr>
        @endif
        @if ($event->end_time !== null)
            <tr>
                <th align="left" style="padding: 6px 8px;">Ends</th>
                <td style="padding: 6px 8px;">{{ $event->end_time->copy()->timezone($displayTimezone)->format('g:i A T') }}</td>
            </tr>
        @endif
        <tr>
            <th align="left" style="padding: 6px 8px;">Teacher(s)</th>
            <td style="padding: 6px 8px;">{{ filled($teacherNames) ? $teacherNames : 'Not assigned' }}</td>
        </tr>
        @php
            $studentNames = $fulfillments
                ->flatMap(fn ($fulfillment) => $fulfillment->students)
                ->unique('id')
                ->map(fn ($student) => $student->displayName())
                ->join(', ');
        @endphp
        @if (filled($studentNames))
            <tr>
                <th align="left" style="padding: 6px 8px;">Student(s)</th>
                <td style="padding: 6px 8px;">{{ $studentNames }}</td>
            </tr>
        @endif
    </table>

    <h2>Unit information</h2>

    @foreach ($fulfillments as $fulfillment)
        <div style="margin-bottom: 20px;">
            <h3>{{ $fulfillment->orderItem->product->name }} — Unit {{ $fulfillment->unit_number }}</h3>
            <p>Order #{{ $fulfillment->orderItem->order_id }}</p>

            @php
                $answers = $fulfillment->orderItem->questionAnswers
                    ->where('unit_number', $fulfillment->unit_number);
            @endphp

            @forelse ($answers as $answer)
                <p style="margin: 4px 0;">
                    <strong>{{ $answer->question }}</strong>: {{ $answer->formattedAnswer() }}
                </p>
            @empty
                <p>No unit information was provided.</p>
            @endforelse
        </div>
    @endforeach
</div>
