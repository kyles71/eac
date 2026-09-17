<div class="space-y-4">
    <div class="grid gap-3 sm:grid-cols-4">
        @foreach (['added', 'removed', 'changed', 'moved'] as $change)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-sm font-medium capitalize">{{ $change }}</div>
                <div class="text-2xl font-semibold">{{ count($comparison[$change]) }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([$left, $right] as $version)
            <div class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <h3 class="text-base font-semibold">{{ $version->versionLabel() }}</h3>
                @include('filament.admin.forms.version-preview', [
                    'blocks' => $version->schema,
                    'comparison' => $comparison,
                ])
            </div>
        @endforeach
    </div>
</div>
