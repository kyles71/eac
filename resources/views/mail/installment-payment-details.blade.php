<div>
    <h2>Payment Details</h2>
    <table role="presentation" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
        <tr>
            <th align="left" style="padding: 6px 8px;">Outcome</th>
            <td style="padding: 6px 8px;">{{ $successful ? 'Successful' : 'Failed' }}</td>
        </tr>
        <tr>
            <th align="left" style="padding: 6px 8px;">Amount</th>
            <td style="padding: 6px 8px;">{{ format_money($installment->amount) }}</td>
        </tr>
        <tr>
            <th align="left" style="padding: 6px 8px;">Processed</th>
            <td style="padding: 6px 8px;">{{ $processedAt->format('F j, Y g:i A T') }}</td>
        </tr>
        @if (filled($stripeStatus))
            <tr>
                <th align="left" style="padding: 6px 8px;">Stripe Status</th>
                <td style="padding: 6px 8px;">{{ $stripeStatus }}</td>
            </tr>
        @endif
        @if (filled($stripePaymentIntentId))
            <tr>
                <th align="left" style="padding: 6px 8px;">Payment Reference</th>
                <td style="padding: 6px 8px;">{{ $stripePaymentIntentId }}</td>
            </tr>
        @endif
        @if (filled($stripeCustomerId))
            <tr>
                <th align="left" style="padding: 6px 8px;">Stripe Customer</th>
                <td style="padding: 6px 8px;">{{ $stripeCustomerId }}</td>
            </tr>
        @endif
        @if (filled($stripePaymentMethodId))
            <tr>
                <th align="left" style="padding: 6px 8px;">Payment Method</th>
                <td style="padding: 6px 8px;">{{ $stripePaymentMethodId }}</td>
            </tr>
        @endif
        @if (filled($failureReason))
            <tr>
                <th align="left" style="padding: 6px 8px;">Reason</th>
                <td style="padding: 6px 8px;">{{ $failureReason }}</td>
            </tr>
        @endif
        @if (filled($failureCode))
            <tr>
                <th align="left" style="padding: 6px 8px;">Failure Code</th>
                <td style="padding: 6px 8px;">{{ $failureCode }}</td>
            </tr>
        @endif
    </table>
</div>
