<div>
    <h2>Order #{{ $order->id }}</h2>
    <p>
        Placed {{ $order->created_at->format('F j, Y') }}<br>
        Order total: {{ $order->formattedTotal() }}
    </p>

    @if ($order->orderItems->isNotEmpty())
        <table role="presentation" style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
            <thead>
                <tr>
                    <th align="left" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Item</th>
                    <th align="right" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Quantity</th>
                    <th align="right" style="border-bottom: 1px solid #d1d5db; padding: 8px;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->orderItems as $orderItem)
                    <tr>
                        <td style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $orderItem->product->name }}</td>
                        <td align="right" style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $orderItem->quantity }}</td>
                        <td align="right" style="border-bottom: 1px solid #e5e7eb; padding: 8px;">{{ $orderItem->formattedTotalPrice() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
