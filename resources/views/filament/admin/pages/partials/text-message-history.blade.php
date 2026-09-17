<div class="space-y-4">
    <p>Sent by {{ $batch->author_name }} · {{ $batch->created_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A') }} · {{ $batch->provider }}</p>
    <div class="rounded-lg bg-gray-50 p-4 whitespace-pre-wrap dark:bg-white/5">{{ $batch->body }}</div>
    <h3 class="font-medium">Selected events</h3>
    <ul class="list-disc space-y-1 pl-5">
        @foreach ($batch->events as $event)
            <li>{{ $event['name'] }} — {{ $event['start_time'] ? \Illuminate\Support\Carbon::parse($event['start_time'])->timezone(config('app.display_timezone'))->format('M j, Y g:i A') : 'Date not set' }} ({{ $event['cancelled'] ? 'Cancelled' : 'Scheduled' }})</li>
        @endforeach
    </ul>
    @if ($batch->warnings)
        <h3 class="font-medium">Students and contacts needing attention at send time</h3>
        <ul class="list-disc space-y-1 pl-5">
            @foreach ($batch->warnings as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    @endif
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr><th class="p-2">Phone / contacts</th><th class="p-2">Result</th><th class="p-2">Provider reference</th></tr></thead>
            <tbody>
                @foreach ($batch->recipients as $recipient)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="p-2">
                            {{ $recipient->phone }}
                            @foreach ($recipient->sources as $source)
                                <div>{{ $source['contact'] }} ({{ $source['student'] }})</div>
                            @endforeach
                        </td>
                        <td class="p-2">
                            <x-filament::badge :color="$recipient->status->getColor()">{{ $recipient->status->getLabel() }}</x-filament::badge>
                            @if ($recipient->error)<p class="mt-1">{{ $recipient->error }}</p>@endif
                        </td>
                        <td class="p-2">
                            <div>Reference: {{ $recipient->id }}</div>
                            @if ($recipient->provider_receipt['id'] ?? null)<div>Provider ID: {{ $recipient->provider_receipt['id'] }}</div>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
