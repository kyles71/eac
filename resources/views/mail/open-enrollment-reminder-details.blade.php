<table role="presentation" style="width: 100%; border-collapse: collapse; margin: 16px 0;">
    <thead>
        <tr>
            <th style="padding: 6px 8px; text-align: left;">Course</th>
            <th style="padding: 6px 8px; text-align: left;">Enrollment</th>
            <th style="padding: 6px 8px; text-align: left;">Purchased</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($enrollments as $enrollment)
            <tr>
                <td style="padding: 6px 8px;">{{ $enrollment->course?->name ?? 'Course' }}</td>
                <td style="padding: 6px 8px;">#{{ $enrollment->id }}</td>
                <td style="padding: 6px 8px;">{{ $enrollment->created_at?->format('F j, Y') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
