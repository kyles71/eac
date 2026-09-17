<?php

declare(strict_types=1);

namespace App\Services\TextMessages;

use App\Contracts\TextMessageTransport;
use Illuminate\Validation\ValidationException;

final class TextMessageTransportManager
{
    public function driver(string $name): TextMessageTransport
    {
        $class = config("text-messages.drivers.{$name}");

        if (! is_string($class) || ! is_a($class, TextMessageTransport::class, true)) {
            throw ValidationException::withMessages(['body' => 'The text message provider is not configured.']);
        }

        return app($class);
    }

    public function ensureEnabled(): void
    {
        if (! config('text-messages.enabled')) {
            throw ValidationException::withMessages(['body' => 'Text messaging is currently disabled.']);
        }
    }

    public function sender(): string
    {
        $sender = mb_trim((string) config('text-messages.sender'));

        if ($sender === '') {
            throw ValidationException::withMessages(['body' => 'The text message sender is not configured.']);
        }

        return $sender;
    }
}
