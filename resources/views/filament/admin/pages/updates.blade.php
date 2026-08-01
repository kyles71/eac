<x-filament-panels::page>
    @if ($feed['status_message'])
        <div @class([
            'rounded-xl border p-4 text-sm',
            'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-600/50 dark:bg-warning-500/10 dark:text-warning-300' => $feed['stale'],
            'border-danger-300 bg-danger-50 text-danger-800 dark:border-danger-600/50 dark:bg-danger-500/10 dark:text-danger-300' => $feed['unavailable'],
        ])>
            {{ $feed['status_message'] }}
        </div>
    @endif

    <div class="space-y-8">
        <x-filament::section
            heading="Available for testing"
            description="These approved changes are included in the latest successful dev deployment."
            icon="heroicon-o-beaker"
        >
            @if ($feed['dev_deployed_at_display'])
                <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                    Dev last deployed {{ $feed['dev_deployed_at_display'] }}
                </p>
            @endif

            <div class="space-y-5">
                @forelse ($feed['testing_updates'] as $update)
                    <article class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                                    {{ $update['note']['title'] }}
                                </h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                    {{ $update['note']['summary'] }}
                                </p>
                            </div>

                            <x-filament::badge color="info" icon="heroicon-o-code-bracket">
                                {{ $update['branch'] }}
                            </x-filament::badge>
                        </div>

                        <div class="mt-5 grid gap-5 lg:grid-cols-2">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">What changed</h4>
                                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                                    @foreach ($update['note']['highlights'] as $highlight)
                                        <li>{{ $highlight }}</li>
                                    @endforeach
                                </ul>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Testing focus</h4>
                                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                                    @foreach ($update['note']['testing_focus'] as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl bg-gray-50 p-5 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        Nothing is currently ready for testing on dev.
                    </div>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section
            heading="Recently released"
            description="Published production updates, newest first."
            icon="heroicon-o-rocket-launch"
        >
            <div class="space-y-6">
                @forelse ($feed['production_releases'] as $release)
                    <article class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-4 dark:border-white/10">
                            <h3 class="font-semibold text-gray-950 dark:text-white">{{ $release['version'] }}</h3>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                Released {{ $release['published_at_display'] }}
                            </span>
                        </div>

                        <div class="mt-5 space-y-6">
                            @foreach ($release['notes'] as $note)
                                <div>
                                    <h4 class="text-base font-semibold text-gray-950 dark:text-white">{{ $note['title'] }}</h4>
                                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $note['summary'] }}</p>

                                    <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                                        @foreach ($note['highlights'] as $highlight)
                                            <li>{{ $highlight }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </article>
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
