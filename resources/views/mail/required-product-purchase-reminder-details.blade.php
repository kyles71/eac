@php($timezone = (string) config('app.display_timezone', config('app.timezone')))

<ul>
    @foreach ($reminders as $reminder)
        <li>
            <a href="{{ \App\Filament\User\Pages\ProductDetails::getUrl(['product' => $reminder['product']], panel: 'user') }}">
                {{ $reminder['product']->name }}
            </a>
            — {{ $reminder['requirement']['remaining'] }} remaining,
            order by {{ $reminder['product']->available_until->timezone($timezone)->format('M j, Y \a\t g:i A') }}
        </li>
    @endforeach
</ul>
