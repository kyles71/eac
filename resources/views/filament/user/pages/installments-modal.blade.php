<div class="space-y-2">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b">
                <th class="text-left py-2 px-1">#</th>
                <th class="text-left py-2 px-1">Amount</th>
                <th class="text-left py-2 px-1">Due Date</th>
                <th class="text-left py-2 px-1">Status</th>
                <th class="text-left py-2 px-1">Paid At</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($installments as $installment)
                <tr class="border-b">
                    <td class="py-2 px-1">{{ $installment->installment_number }}</td>
                    <td class="py-2 px-1">${{ number_format($installment->amount / 100, 2) }}</td>
                    <td class="py-2 px-1">{{ $installment->due_date->format('M j, Y') }}</td>
                    <td class="py-2 px-1">
                        <span @class([
                            'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                            'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/20' => $installment->status === \App\Enums\InstallmentStatus::Paid,
                            'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-400/10 dark:text-yellow-500 dark:ring-yellow-400/20' => $installment->status === \App\Enums\InstallmentStatus::Pending,
                            'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/20' => $installment->status === \App\Enums\InstallmentStatus::Failed,
                            'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-400/10 dark:text-orange-400 dark:ring-orange-400/20' => $installment->status === \App\Enums\InstallmentStatus::Overdue,
                        ])>
                            {{ $installment->status->getLabel() }}
                        </span>
                    </td>
                    <td class="py-2 px-1">{{ $installment->paid_at?->format('M j, Y') ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
