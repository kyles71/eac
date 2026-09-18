<div class="space-y-3">
    @foreach ($blocks as $block)
        @php
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $identity = $data['key'] ?? null;
            $change = collect(['added', 'removed', 'changed', 'moved'])
                ->first(fn ($type) => $identity && in_array($identity, $comparison[$type], true));
        @endphp

        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="flex items-center justify-between gap-2">
                <strong>{{ $data['heading'] ?? $data['label'] ?? str($block['type'] ?? 'component')->headline() }}</strong>
                @if ($change)
                    <span class="rounded bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">
                        {{ str($change)->headline() }}
                    </span>
                @endif
            </div>

            @if (filled($data['description'] ?? null))
                <p class="mt-1 text-sm text-gray-500">{{ $data['description'] }}</p>
            @endif

            @if (filled($data['content'] ?? null))
                <p class="mt-1 text-sm">{{ $data['content'] }}</p>
            @endif

            @if (! empty($data['components']) && is_array($data['components']))
                <div class="mt-3 border-l-2 border-gray-200 pl-3 dark:border-white/10">
                    @include('filament.admin.forms.version-preview', [
                        'blocks' => $data['components'],
                        'comparison' => $comparison,
                    ])
                </div>
            @endif
        </div>
    @endforeach
</div>
