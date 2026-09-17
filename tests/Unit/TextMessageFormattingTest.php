<?php

declare(strict_types=1);

use App\Support\TextMessages\MessageLength;
use App\Support\TextMessages\PhoneNumber;
use Illuminate\Validation\ValidationException;

it('normalizes equivalent US phone numbers', function (string $phone): void {
    expect(PhoneNumber::normalize($phone))->toBe('+13135550123');
})->with(['(313) 555-0123', '313.555.0123', '13135550123', '+1 (313) 555-0123']);

it('rejects ambiguous or invalid phone numbers', function (string $phone): void {
    expect(PhoneNumber::normalize($phone))->toBeNull();
})->with(['', '555-0123', '3135550123 ext 12', '+443135550123', '0000000000', '+3135550123', 'call 3135550123']);

it('counts GSM extension characters and UTF16 units at multipart boundaries', function (): void {
    expect(MessageLength::measure(str_repeat('a', 160))['parts'])->toBe(1)
        ->and(MessageLength::measure(str_repeat('a', 161))['parts'])->toBe(2)
        ->and(MessageLength::measure(str_repeat('^', 80))['parts'])->toBe(1)
        ->and(MessageLength::measure(str_repeat('^', 81))['parts'])->toBe(2)
        ->and(MessageLength::measure(str_repeat('漢', 70))['parts'])->toBe(1)
        ->and(MessageLength::measure(str_repeat('漢', 71))['parts'])->toBe(2)
        ->and(MessageLength::measure(str_repeat("\u{C2A1}", 71))['parts'])->toBe(2)
        ->and(MessageLength::measure(str_repeat('😀', 35))['parts'])->toBe(1)
        ->and(MessageLength::measure(str_repeat('😀', 36))['parts'])->toBe(2);
    MessageLength::validate(str_repeat('a', 918));
    MessageLength::validate(str_repeat('漢', 402));
});

it('rejects blank and oversized messages without truncating', function (string $body): void {
    expect(fn () => MessageLength::validate($body))->toThrow(ValidationException::class);
})->with(['  ', str_repeat('a', 919), str_repeat('漢', 403)]);
