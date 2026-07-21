@php
    use App\Enums\ManagedBannerRenderLocation;

    $renderPreviewBanner = fn(ManagedBannerRenderLocation $location): string => $renderLocation === $location
        ? (string) view('filament.admin.resources.managed-banners.live-preview-banner', [
            'activeRenderLocation' => $location,
            'ctaLabel' => $ctaLabel,
            'ctaNewTab' => $ctaNewTab,
            'ctaUrl' => $ctaUrl,
            'icon' => $icon,
            'message' => $message,
            'title' => $title,
            'tone' => $tone,
        ])
        : '';
@endphp

<div class="space-y-3" data-managed-banner-preview-active="{{ $renderLocation->value }}">
    <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50 shadow-sm dark:border-white/10 dark:bg-gray-950"
        data-managed-banner-preview-canvas>
        {!! $renderPreviewBanner(ManagedBannerRenderLocation::TopbarBefore) !!}
        <div class="flex min-h-[34rem] flex-col 2xl:flex-row">
            <aside
                class="border-b border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900 2xl:w-72 2xl:border-b-0 2xl:border-r">
                <div class="mb-4 flex items-center gap-3">
                    <div class="h-9 w-9 rounded-md bg-primary-500"></div>
                    <div>
                        <div class="h-2.5 w-24 rounded-full bg-gray-900 dark:bg-white"></div>
                        <div class="mt-2 h-2 w-16 rounded-full bg-gray-300 dark:bg-gray-700"></div>
                    </div>
                </div>

                <nav class="space-y-3 rounded-md border border-gray-100 p-3 dark:border-white/10">
                    {!! $renderPreviewBanner(ManagedBannerRenderLocation::SidebarNavStart) !!}

                    <div class="space-y-2">
                        <div class="flex items-center gap-2 rounded-md bg-gray-100 px-3 py-2 dark:bg-white/10">
                            <div class="h-4 w-4 rounded bg-gray-400 dark:bg-gray-500"></div>
                            <div class="h-2.5 w-24 rounded-full bg-gray-500 dark:bg-gray-400"></div>
                        </div>
                        <div class="flex items-center gap-2 rounded-md px-3 py-2">
                            <div class="h-4 w-4 rounded bg-gray-300 dark:bg-gray-700"></div>
                            <div class="h-2.5 w-20 rounded-full bg-gray-300 dark:bg-gray-700"></div>
                        </div>
                        <div class="flex items-center gap-2 rounded-md px-3 py-2">
                            <div class="h-4 w-4 rounded bg-gray-300 dark:bg-gray-700"></div>
                            <div class="h-2.5 w-28 rounded-full bg-gray-300 dark:bg-gray-700"></div>
                        </div>
                    </div>

                    {!! $renderPreviewBanner(ManagedBannerRenderLocation::SidebarNavEnd) !!}
                </nav>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col bg-gray-100 dark:bg-gray-950">
                <div
                    class="flex h-14 items-center justify-between border-b border-gray-200 bg-white px-4 dark:border-white/10 dark:bg-gray-900">
                    <div class="h-2.5 w-36 rounded-full bg-gray-300 dark:bg-gray-700"></div>
                    <div class="flex items-center gap-2">
                        <div class="h-8 w-8 rounded-full bg-gray-200 dark:bg-gray-800"></div>
                        <div class="h-8 w-8 rounded-full bg-gray-200 dark:bg-gray-800"></div>
                    </div>
                </div>

                <main class="min-w-0 flex-1 space-y-4 p-4">
                    {!! $renderPreviewBanner(ManagedBannerRenderLocation::ContentStart) !!}

                    <section
                        class="space-y-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                        {!! $renderPreviewBanner(ManagedBannerRenderLocation::PageStart) !!}

                        <header
                            class="flex flex-col gap-3 border-b border-gray-100 pb-4 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                            <div class="space-y-2">
                                <div class="h-3 w-52 rounded-full bg-gray-900 dark:bg-white"></div>
                                <div class="h-2.5 w-64 max-w-full rounded-full bg-gray-300 dark:bg-gray-700"></div>
                            </div>
                            <div class="h-8 w-24 rounded-md bg-primary-500"></div>
                        </header>

                        <div class="space-y-3">
                            {!! $renderPreviewBanner(ManagedBannerRenderLocation::PageHeaderWidgetsBefore) !!}

                            <div class="grid gap-3 md:grid-cols-3">
                                <div class="rounded-md border border-gray-100 p-3 dark:border-white/10">
                                    <div class="h-2.5 w-16 rounded-full bg-gray-300 dark:bg-gray-700"></div>
                                    <div class="mt-4 h-5 w-20 rounded-full bg-gray-700 dark:bg-gray-300"></div>
                                </div>
                                <div class="rounded-md border border-gray-100 p-3 dark:border-white/10">
                                    <div class="h-2.5 w-20 rounded-full bg-gray-300 dark:bg-gray-700"></div>
                                    <div class="mt-4 h-5 w-24 rounded-full bg-gray-700 dark:bg-gray-300"></div>
                                </div>
                                <div class="rounded-md border border-gray-100 p-3 dark:border-white/10">
                                    <div class="h-2.5 w-14 rounded-full bg-gray-300 dark:bg-gray-700"></div>
                                    <div class="mt-4 h-5 w-16 rounded-full bg-gray-700 dark:bg-gray-300"></div>
                                </div>
                            </div>

                            {!! $renderPreviewBanner(ManagedBannerRenderLocation::PageHeaderWidgetsAfter) !!}
                        </div>

                        <div class="space-y-3 rounded-md border border-gray-100 p-3 dark:border-white/10">
                            <div class="h-3 w-40 rounded-full bg-gray-700 dark:bg-gray-300"></div>
                            <div class="grid gap-2">
                                <div class="h-10 rounded-md bg-gray-100 dark:bg-white/10"></div>
                                <div class="h-10 rounded-md bg-gray-100 dark:bg-white/10"></div>
                                <div class="h-10 rounded-md bg-gray-100 dark:bg-white/10"></div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            {!! $renderPreviewBanner(ManagedBannerRenderLocation::PageFooterWidgetsBefore) !!}

                            <div class="grid gap-3 md:grid-cols-2">
                                <div class="rounded-md border border-gray-100 p-3 dark:border-white/10">
                                    <div class="h-2.5 w-24 rounded-full bg-gray-300 dark:bg-gray-700"></div>
                                    <div class="mt-4 h-12 rounded-md bg-gray-100 dark:bg-white/10"></div>
                                </div>
                                <div class="rounded-md border border-gray-100 p-3 dark:border-white/10">
                                    <div class="h-2.5 w-28 rounded-full bg-gray-300 dark:bg-gray-700"></div>
                                    <div class="mt-4 h-12 rounded-md bg-gray-100 dark:bg-white/10"></div>
                                </div>
                            </div>

                            {!! $renderPreviewBanner(ManagedBannerRenderLocation::PageFooterWidgetsAfter) !!}
                        </div>

                        {!! $renderPreviewBanner(ManagedBannerRenderLocation::PageEnd) !!}
                    </section>

                    {!! $renderPreviewBanner(ManagedBannerRenderLocation::ContentEnd) !!}
                </main>
            </div>
        </div>
    </div>
</div>
