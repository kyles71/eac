<div>
    <h2>Payment Plan</h2>
    <table role="presentation" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
        <tr>
            <th align="left" style="padding: 6px 8px;">Payment Plan</th>
            <td style="padding: 6px 8px;">#{{ $paymentPlan->id }}</td>
        </tr>
        <tr>
            <th align="left" style="padding: 6px 8px;">Installment</th>
            <td style="padding: 6px 8px;">#{{ $installment->installment_number }} of {{ $paymentPlan->number_of_installments }}</td>
        </tr>
        <tr>
            <th align="left" style="padding: 6px 8px;">Due Date</th>
            <td style="padding: 6px 8px;">{{ $installment->due_date->format('F j, Y') }}</td>
        </tr>
        <tr>
            <th align="left" style="padding: 6px 8px;">Installment Status</th>
            <td style="padding: 6px 8px;">{{ $installment->status->value }}</td>
        </tr>
        <tr>
            <th align="left" style="padding: 6px 8px;">Plan Total</th>
            <td style="padding: 6px 8px;">{{ format_money($paymentPlan->total_amount) }}</td>
        </tr>
        <tr>
            <th align="left" style="padding: 6px 8px;">Paid</th>
            <td style="padding: 6px 8px;">{{ format_money($paymentPlan->amountPaid()) }}</td>
        </tr>
        <tr>
            <th align="left" style="padding: 6px 8px;">Remaining</th>
            <td style="padding: 6px 8px;">{{ format_money($paymentPlan->remainingBalance()) }}</td>
        </tr>
        @if ($installment->retry_count > 0)
            <tr>
                <th align="left" style="padding: 6px 8px;">Failed Attempts</th>
                <td style="padding: 6px 8px;">{{ $installment->retry_count }}</td>
            </tr>
        @endif
    </table>
</div>
