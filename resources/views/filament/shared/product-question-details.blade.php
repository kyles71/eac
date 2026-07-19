<div class="mt-2 space-y-2 text-xs text-gray-600 dark:text-gray-400">
    @foreach ($units as $unit)
        <div>
            <p class="font-medium text-gray-950 dark:text-white">{{ $unit['label'] }}</p>

            <dl class="space-y-0.5">
                @foreach ($unit['answers'] as $answer)
                    <div class="flex flex-wrap gap-x-3">
                        <dt class="font-bold">{{ $answer['question'] }}</dt>
                        <dd>{{ $answer['answer'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach
</div>
