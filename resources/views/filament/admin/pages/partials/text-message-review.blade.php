@if ($review)
    <div class="space-y-4">
        <p class="font-medium">{{ count($review['snapshot']['recipients']) }} unique phone numbers will receive this message.</p>
        <div class="rounded-lg bg-gray-50 p-4 whitespace-pre-wrap dark:bg-white/5">{{ $review['body'] }}</div>
        <p class="text-sm text-gray-500">Sending this message does not change event status.</p>
        <h3 class="font-medium">Selected events</h3>
        <ul class="list-disc space-y-1 pl-5">
            @foreach ($review['snapshot']['events'] as $event)
                <li>{{ $event['name'] }} — {{ $event['start_time'] ? \Illuminate\Support\Carbon::parse($event['start_time'])->timezone(config('app.display_timezone'))->format('M j, Y g:i A') : 'Date not set' }} ({{ $event['cancelled'] ? 'Cancelled' : 'Scheduled' }})</li>
            @endforeach
        </ul>
        <h3 class="font-medium">Recipients</h3>
        <ul class="space-y-2">
            @foreach ($review['snapshot']['recipients'] as $recipient)
                <li>
                    <span class="font-medium">{{ $recipient['phone'] }}</span>
                    <span class="text-sm">— {{ collect($recipient['sources'])->map(fn ($source) => $source['contact'].' ('.$source['student'].')')->unique()->implode(', ') }}</span>
                </li>
            @endforeach
        </ul>
        @if ($review['snapshot']['warnings'])
            <h3 class="font-medium">Students and contacts needing attention</h3>
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($review['snapshot']['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
