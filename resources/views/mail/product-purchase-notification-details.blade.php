<div>
    <h2>Order #{{ $order->id }}</h2>
    <p>
        Purchased {{ $order->created_at->format('F j, Y') }}<br>
        Order total: {{ $order->formattedTotal() }}
    </p>

    @foreach ($orderItems as $orderItem)
        <div style="margin-bottom: 24px;">
            <h3>{{ $orderItem->product->name }}</h3>
            <p>
                Quantity: {{ $orderItem->quantity }}<br>
                Unit price: {{ $orderItem->formattedUnitPrice() }}<br>
                Line total: {{ $orderItem->formattedTotalPrice() }}
            </p>

            @foreach ($orderItem->questionAnswers->groupBy('unit_number') as $unitNumber => $answers)
                <p>
                    @if ($orderItem->quantity > 1)
                        <strong>Item {{ $unitNumber }} of {{ $orderItem->quantity }}</strong><br>
                    @endif
                    @foreach ($answers as $answer)
                        <strong>{{ $answer->question }}</strong>: {{ $answer->formattedAnswer() }}<br>
                    @endforeach
                </p>
            @endforeach
        </div>
    @endforeach
</div>
