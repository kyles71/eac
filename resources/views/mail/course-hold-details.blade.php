<table role="presentation" style="width: 100%; border-collapse: collapse; margin: 16px 0;">
    <thead>
        <tr>
            <th style="text-align: left; padding: 8px; border-bottom: 1px solid #d1d5db;">Class</th>
            <th style="text-align: center; padding: 8px; border-bottom: 1px solid #d1d5db;">Seats</th>
            <th style="text-align: right; padding: 8px; border-bottom: 1px solid #d1d5db;">Held Price</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($seatGroups as $seats)
            @php($seat = $seats->first())
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #e5e7eb;">{{ $seat->course->name }}</td>
                <td style="text-align: center; padding: 8px; border-bottom: 1px solid #e5e7eb;">{{ $seats->count() }}</td>
                <td style="text-align: right; padding: 8px; border-bottom: 1px solid #e5e7eb;">{{ format_money($seat->locked_unit_price) }} each</td>
            </tr>
        @empty
            <tr>
                <td colspan="3" style="padding: 8px;">No unpurchased seats remain on this hold.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<p><strong>Expires:</strong> {{ $hold->expires_at->timezone(config('app.display_timezone', config('app.timezone')))->format('F j, Y \a\t g:i A') }}</p>

@if ($seatGroups->isNotEmpty())
    <p><a href="{{ $purchaseUrl }}" style="display:inline-block;padding:12px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px">View Held Classes</a></p>
@endif
