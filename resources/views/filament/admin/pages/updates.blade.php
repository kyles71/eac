<x-filament-panels::page>
    @if ($feed['status_message'])
        <div @class([
            'rounded-xl border p-4 text-sm',
            'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-600/50 dark:bg-warning-500/10 dark:text-warning-300' =>
                $feed['stale'],
            'border-danger-300 bg-danger-50 text-danger-800 dark:border-danger-600/50 dark:bg-danger-500/10 dark:text-danger-300' =>
                $feed['unavailable'],
        ])>
            {{ $feed['status_message'] }}
        </div>
    @endif

    <div class="space-y-8">
        <x-filament::section heading="Available for testing"
            description="These approved changes are included in the latest successful dev deployment."
            icon="heroicon-o-beaker">
            @if ($feed['dev_deployed_at_display'])
                <x-slot name="afterHeader">
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        Dev last deployed {{ $feed['dev_deployed_at_display'] }}
                    </span>
                </x-slot>
            @endif

            <div class="space-y-5">
                @forelse ($feed['testing_updates'] as $update)
                    <x-filament::section :heading="$update['branch']" heading-tag="h3" collapsible secondary
                        class="[&_a]:font-medium [&_a]:text-primary-600 [&_a]:underline [&_code]:rounded [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:py-0.5 dark:[&_a]:text-primary-400 dark:[&_code]:bg-white/10">
                        <x-slot name="afterHeader">
                            <button type="button" data-update-timestamp-toggle x-on:click="isCollapsed = ! isCollapsed"
                                x-bind:aria-expanded="(! isCollapsed).toString()"
                                class="cursor-pointer text-sm text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                @if ($update['merged_into_dev_at_display'])
                                    Merged into dev {{ $update['merged_into_dev_at_display'] }}
                                @else
                                    Initial dev merge time unavailable
                                @endif
                            </button>
                        </x-slot>

                        <h4 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ safe_inline_markdown($update['note']['title']) }}
                        </h4>
                        <div class="mt-2 text-sm text-gray-600 [&_p+p]:mt-2 dark:text-gray-300">
                            {{ safe_markdown($update['note']['summary']) }}
                        </div>

                        <div class="mt-5 grid gap-5 lg:grid-cols-2">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">What changed</h4>
                                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                                    @foreach ($update['note']['highlights'] as $highlight)
                                        <li>{{ safe_inline_markdown($highlight) }}</li>
                                    @endforeach
                                </ul>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Testing focus</h4>
                                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                                    @foreach ($update['note']['testing_focus'] as $item)
                                        <li>{{ safe_inline_markdown($item) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </x-filament::section>
                @empty
                    <div class="rounded-xl bg-gray-50 p-5 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        Nothing is currently ready for testing on dev.
                    </div>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section heading="Recently released" description="Published production updates, newest first."
            icon="heroicon-o-rocket-launch">
            <div class="space-y-6">
                @forelse ($feed['production_releases'] as $release)
                    <x-filament::section :heading="$release['version']" heading-tag="h3" collapsible secondary
                        class="[&_a]:font-medium [&_a]:text-primary-600 [&_a]:underline [&_code]:rounded [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:py-0.5 dark:[&_a]:text-primary-400 dark:[&_code]:bg-white/10">
                        <x-slot name="afterHeader">
                            <button type="button" data-update-timestamp-toggle x-on:click="isCollapsed = ! isCollapsed"
                                x-bind:aria-expanded="(! isCollapsed).toString()"
                                class="cursor-pointer text-sm text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                Released {{ $release['published_at_display'] }}
                            </button>
                        </x-slot>

                        <div class="space-y-6">
                            @foreach ($release['notes'] as $note)
                                <div>
                                    <h4 class="text-base font-semibold text-gray-950 dark:text-white">
                                        {{ safe_inline_markdown($note['title']) }}
                                    </h4>
                                    <div class="mt-2 text-sm text-gray-600 [&_p+p]:mt-2 dark:text-gray-300">
                                        {{ safe_markdown($note['summary']) }}
                                    </div>

                                    <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                                        @foreach ($note['highlights'] as $highlight)
                                            <li>{{ safe_inline_markdown($highlight) }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::section>
                @empty
                    <div class="rounded-xl bg-gray-50 p-5 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        No production updates have been published in the new format yet.
                    </div>
                @endforelse
            </div>
        </x-filament::section>

        <p class="text-right text-xs text-gray-500 dark:text-gray-400">
            Last checked {{ $feed['refreshed_at_display'] }}
        </p>
    </div>
</x-filament-panels::page>
