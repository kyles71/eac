@php
    $roleDescription = 'Viewer: view the board and cards. Contributor: submit and comment; collaborative boards also allow card editing, moving, and assignment. Manager: manage the board, stages, cards, and members.';
@endphp

<span
    class="inline-flex items-center gap-1 align-middle"
    style="display: inline-flex; align-items: center; gap: 0.25rem; vertical-align: middle;"
>
    <span>Role<sup class="fi-fo-table-repeater-header-required-mark">*</sup></span>

    <span
        class="inline-flex items-center text-gray-500 dark:text-gray-400"
        style="display: inline-flex; align-items: center; vertical-align: middle;"
        aria-label="Board role descriptions"
        tabindex="0"
        x-tooltip="{
            content: @js($roleDescription),
            theme: $store.theme,
        }"
    >
        <x-filament::icon icon="heroicon-m-question-mark-circle" class="h-4 w-4" />
    </span>
</span>
