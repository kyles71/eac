<div>
    <h2>Revised Payment Schedule</h2>
    <table role="presentation" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
        <thead>
            <tr>
                <th align="left" style="padding: 6px 8px;">Installment</th>
                <th align="left" style="padding: 6px 8px;">Amount</th>
                <th align="left" style="padding: 6px 8px;">Due Date</th>
                <th align="left" style="padding: 6px 8px;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($installments as $installment)
                <tr>
                    <td style="padding: 6px 8px;">#{{ $installment->installment_number }}</td>
                    <td style="padding: 6px 8px;">{{ format_money($installment->amount) }}</td>
                    <td style="padding: 6px 8px;">{{ $installment->due_date->format('F j, Y') }}</td>
                    <td style="padding: 6px 8px;">{{ $installment->status->value }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
