<div>
    <h2>Order #{{ $order->id }}</h2>

    <table role="presentation" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
        <thead>
            <tr>
                <th align="left" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Item</th>
                <th align="right" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Quantity</th>
                <th align="right" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Unit price</th>
                <th align="right" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->orderItems as $orderItem)
                <tr>
                    <td style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $orderItem->product->name }}</td>
                    <td align="right" style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $orderItem->quantity }}</td>
                    <td align="right" style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $orderItem->formattedUnitPrice() }}</td>
                    <td align="right" style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $orderItem->formattedTotalPrice() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table role="presentation" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
        <tr><td style="padding: 4px 8px;">Subtotal</td><td align="right" style="padding: 4px 8px;">{{ $order->formattedSubtotal() }}</td></tr>
        @if ($order->discount_amount > 0)
            <tr><td style="padding: 4px 8px;">Discount</td><td align="right" style="padding: 4px 8px;">−{{ format_money($order->discount_amount) }}</td></tr>
        @endif
        @if ($order->restricted_credit_applied > 0)
            <tr><td style="padding: 4px 8px;">Limited Use Credit</td><td align="right" style="padding: 4px 8px;">−{{ format_money($order->restricted_credit_applied) }}</td></tr>
        @endif
        @if ($order->credit_applied > 0)
            <tr><td style="padding: 4px 8px;">Store Credit</td><td align="right" style="padding: 4px 8px;">−{{ format_money($order->credit_applied) }}</td></tr>
        @endif
        @if ($order->payment_plan_fee > 0)
            <tr><td style="padding: 4px 8px;">Payment Plan Fee</td><td align="right" style="padding: 4px 8px;">{{ format_money($order->payment_plan_fee) }}</td></tr>
        @endif
        <tr>
            <td style="border-top: 1px solid #d1d5db; padding: 8px; font-weight: 700;">Order Total</td>
            <td align="right" style="border-top: 1px solid #d1d5db; padding: 8px; font-weight: 700;">{{ $order->formattedTotal() }}</td>
        </tr>
    </table>

    @if ($order->paymentPlan !== null)
        <h2>Payment Plan</h2>
        <p>
            Paid: {{ format_money($order->paymentPlan->amountPaid()) }}<br>
            Remaining: {{ format_money($order->paymentPlan->remainingBalance()) }}
        </p>
        <table role="presentation" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th align="left" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Payment</th>
                    <th align="left" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Due</th>
                    <th align="right" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Amount</th>
                    <th align="right" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->paymentPlan->installments->sortBy('installment_number') as $installment)
                    <tr>
                        <td style="border-bottom: 1px solid #e5e7eb; padding: 8px;">#{{ $installment->installment_number }}</td>
                        <td style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $installment->due_date->format('M j, Y') }}</td>
                        <td align="right" style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ format_money($installment->amount) }}</td>
                        <td align="right" style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $installment->status->value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p><strong>Payment received:</strong> {{ $order->formattedTotal() }}</p>
    @endif
</div>
