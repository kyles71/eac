@php
    $groups = [
        'payments' => ['label' => 'Urgent Payments', 'icon' => 'heroicon-o-credit-card', 'iconColor' => 'text-danger-500'],
        'forms' => ['label' => 'Forms', 'icon' => 'heroicon-o-document-text', 'iconColor' => 'text-warning-500'],
        'class_assignments' => ['label' => 'Class Assignments', 'icon' => 'heroicon-o-academic-cap', 'iconColor' => 'text-warning-500'],
        'held_classes' => ['label' => 'Held Seats', 'icon' => 'heroicon-o-clock', 'iconColor' => 'text-warning-500'],
        'required_products' => ['label' => 'Required Purchases', 'icon' => 'heroicon-o-shopping-bag', 'iconColor' => 'text-warning-500'],
        'private_lessons' => ['label' => 'Private Lessons', 'icon' => 'heroicon-o-user', 'iconColor' => 'text-warning-500'],
    ];
@endphp

<div class="space-y-6">
    @foreach ($groups as $group => $details)
        @php($tasks = $groupedTasks->get($group, collect()))

        @if ($tasks->isNotEmpty())
            <section aria-labelledby="attention-group-{{ $group }}">
                <h3 id="attention-group-{{ $group }}" class="mb-3 flex items-center gap-2 text-base font-semibold text-gray-950 dark:text-white">
                    <x-filament::icon :icon="$details['icon']" @class(['h-5 w-5', $details['iconColor']]) />
                    {{ $details['label'] }}
                </h3>

                <div class="space-y-3">
                    @foreach ($tasks as $task)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                            <p class="font-medium text-gray-950 dark:text-white">{{ $task['title'] }}</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $task['description'] }}</p>

                            <x-filament::button
                                :color="$task['color']"
                                :href="$task['url']"
                                tag="a"
                                size="sm"
                                class="mt-3 w-full sm:w-auto"
                            >
                                {{ $task['action'] }}
                            </x-filament::button>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
</div>
