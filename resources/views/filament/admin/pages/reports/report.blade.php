<x-filament-panels::page>
    {{ $this->table }}

    @if ($this->getReportFooterRows() !== [])
        <x-filament::section heading="Report Totals">
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead>
                        <tr>
                            @foreach ($this->getReportHeaders() as $label)
                                <th class="whitespace-nowrap px-3 py-2 text-left font-semibold text-gray-950 dark:text-white">
                                    {{ $label }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($this->getReportFooterRows() as $row)
                            <tr>
                                @foreach ($this->getReportHeaders() as $key => $label)
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-200">
                                        {{ $row[$key] ?? '' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
