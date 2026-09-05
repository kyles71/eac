@props(['columnId', 'record'])

@php
    use App\Models\BoardItem;

    $processedRecordActions = $this->getBoard()->getBoardRecordActions($record);
    $hasActions = ! empty($processedRecordActions);
    $cardAction = $this->getBoard()->getCardAction();
    $hasCardAction = $cardAction !== null;
    $hasPositionIdentifier = $this->getBoard()->getPositionIdentifierAttribute() !== null;
    $item = $record['model'];
    $priority = $item instanceof BoardItem ? $item->priority : null;
    $type = $item instanceof BoardItem ? $item->type : null;
    $commentCount = $item instanceof BoardItem ? (int) ($item->comments_count ?? 0) : 0;

    $cardActionInstance = $this->getBoard()->resolveCardAction($processedRecordActions);
    $cardActionUrl = $cardActionInstance?->shouldPostToUrl()
        ? null
        : $cardActionInstance?->getUrl();
    $hasCardActionUrl = filled($cardActionUrl);
    $cardActionOpensNewTab = $hasCardActionUrl && $cardActionInstance->shouldOpenUrlInNewTab();
@endphp

<div
    @class([
        'flowforge-card mb-3 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm transition-all duration-200 hover:shadow-md dark:border-gray-700 dark:bg-gray-900',
        'cursor-pointer transition-all duration-100 ease-in-out hover:border-gray-400 hover:shadow-lg active:shadow-md' => $hasCardAction,
        'cursor-grab hover:cursor-grabbing' => $hasPositionIdentifier && ! $hasCardAction,
        'cursor-default' => ! $hasActions && ! $hasCardAction && ! $hasPositionIdentifier,
    ])
    x-sortable-item="{{ $record['id'] }}"
    data-card-id="{{ $record['id'] }}"
    @if($hasPositionIdentifier)
        x-sortable-handle
    @endif
    @if($hasCardActionUrl)
        role="link"
        tabindex="0"
        aria-label="View {{ $record['title'] }}"
        @if($cardActionOpensNewTab)
            x-on:click="window.open(@js($cardActionUrl), '_blank', 'noopener,noreferrer')"
            x-on:keydown.enter.prevent="window.open(@js($cardActionUrl), '_blank', 'noopener,noreferrer')"
            x-on:keydown.space.prevent="window.open(@js($cardActionUrl), '_blank', 'noopener,noreferrer')"
        @else
            x-on:click="Livewire.navigate(@js($cardActionUrl))"
            x-on:keydown.enter.prevent="Livewire.navigate(@js($cardActionUrl))"
            x-on:keydown.space.prevent="Livewire.navigate(@js($cardActionUrl))"
        @endif
    @elseif($hasCardAction)
        role="button"
        tabindex="0"
        wire:click="mountAction('{{ $cardAction }}', [], @js(['recordKey' => $record['id']]))"
        wire:keydown.enter="mountAction('{{ $cardAction }}', [], @js(['recordKey' => $record['id']]))"
        wire:keydown.space.prevent="mountAction('{{ $cardAction }}', [], @js(['recordKey' => $record['id']]))"
    @endif
    data-position="{{ $record['position'] ?? '' }}"
>
    <div class="flowforge-card-content">
        <div class="flex items-start gap-3 p-3 pb-2">
            <h4 class="min-w-0 flex-1 break-words text-sm font-semibold text-gray-900 dark:text-white">
                {{ $record['title'] }}
            </h4>

            <div class="grid flex-shrink-0 grid-cols-[auto_auto] items-center gap-x-1.5 gap-y-1">
                @if($priority)
                    <x-filament::badge
                        tag="div"
                        :color="$priority->getColor()"
                        size="sm"
                        :aria-label="'Priority: '.$priority->getLabel()"
                    >
                        {{ $priority->getLabel() }}
                    </x-filament::badge>
                @else
                    <span></span>
                @endif

                @if($hasActions)
                    <div class="justify-self-end" x-on:click.stop x-on:keydown.stop>
                        <x-filament-actions::group
                            :actions="$processedRecordActions"
                            dropdown-placement="bottom-start"
                        />
                    </div>
                @else
                    <span></span>
                @endif

                @if($type)
                    <x-filament::badge
                        tag="div"
                        :color="$type->getColor()"
                        size="sm"
                        :aria-label="'Type: '.$type->getLabel()"
                    >
                        {{ $type->getLabel() }}
                    </x-filament::badge>
                @else
                    <span></span>
                @endif

                <div
                    class="flex items-center justify-self-end text-sm text-gray-600 dark:text-gray-300"
                    aria-label="{{ trans_choice(':count comment|:count comments', $commentCount, ['count' => $commentCount]) }}"
                >
                    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="me-1 h-4 w-4" />
                    <span>{{ $commentCount }}</span>
                </div>
            </div>
        </div>

        <div class="px-3 pb-3 pt-1">
            @if(filled($record['schema']))
                {{ $record['schema'] }}
            @endif
        </div>
    </div>
</div>
