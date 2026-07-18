@php
    $previewLabel = $heading ?? $label ?? null;
    $previewContent = is_string($content ?? null)
        ? str(strip_tags($content))->squish()->limit(160)
        : null;
    $fieldCount = is_array($components ?? null) ? count($components) : null;
@endphp

<div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
    @if (filled($previewLabel))
        <div class="font-medium text-gray-950 dark:text-white">{{ $previewLabel }}</div>
    @endif

    @if (filled($previewContent))
        <div>{{ $previewContent }}</div>
    @endif

    @if ($fieldCount !== null)
        <div>{{ trans_choice(':count field|:count fields', $fieldCount, ['count' => $fieldCount]) }}</div>
    @endif
</div>
