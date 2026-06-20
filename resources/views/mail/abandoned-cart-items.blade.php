<table role="presentation" style="width: 100%; border-collapse: collapse; margin: 16px 0;">
    <thead>
        <tr>
            <th style="padding: 6px 8px; text-align: left;">Item</th>
            <th style="padding: 6px 8px; text-align: right;">Quantity</th>
            <th style="padding: 6px 8px; text-align: right;">Price</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($cartItems as $cartItem)
            <tr>
                <td style="padding: 6px 8px;">{{ $cartItem->product->name }}</td>
                <td style="padding: 6px 8px; text-align: right;">{{ $cartItem->quantity }}</td>
                <td style="padding: 6px 8px; text-align: right;">{{ format_money($cartItem->product->price * $cartItem->quantity) }}</td>
            </tr>
        @endforeach
        <tr>
            <th colspan="2" style="padding: 8px; text-align: right;">Total</th>
            <td style="padding: 8px; text-align: right;">{{ format_money($total) }}</td>
        </tr>
    </tbody>
</table>
