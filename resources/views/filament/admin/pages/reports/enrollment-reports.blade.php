@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    {{ $this->filtersForm }}

    {{ $this->dashboardWidgets }}

    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->availableReports() as $report)
            <a
                href="{{ $report->page()::getUrl() }}"
                class="group rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 transition hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10 dark:hover:ring-primary-500"
            >
                <div class="flex items-start gap-4">
                    <div class="rounded-lg bg-primary-50 p-3 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                        <x-filament::icon :icon="Heroicon::OutlinedDocumentChartBar" class="size-6" />
                    </div>

                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold text-gray-950 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                            {{ $report->label() }}
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">
                            {{ $report->description() }}
                        </p>
                    </div>

                    <x-filament::icon :icon="Heroicon::ChevronRight" class="mt-1 size-5 text-gray-400 group-hover:text-primary-500" />
                </div>
            </a>
        @empty
            <x-filament::section heading="No reports available" class="md:col-span-2 xl:col-span-3">
                Your account does not currently have permission to open a report in this category.
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
