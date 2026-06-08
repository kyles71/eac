<?php

declare(strict_types=1);

namespace App\Support;

use App\Mail\Transports\TextmagicTransport;
use App\Services\Mail\TextmagicEmailService;
use GuzzleHttp\Client;
use Symfony\Component\Mailer\Exception\TransportException;
use TextMagic\Api\TextMagicApi;
use TextMagic\Configuration;

final class TextmagicMailTransportFactory
{
    /**
     * @param  array<string, mixed>  $mailerConfig
     */
    public static function make(array $mailerConfig): TextmagicTransport
    {
        $textmagicConfig = (new Configuration())
            ->setUsername(self::requiredServiceConfig('username'))
            ->setPassword(self::requiredServiceConfig('api_key'));

        return new TextmagicTransport(
            client: new TextmagicEmailService(
                new TextMagicApi(new Client(), $textmagicConfig),
            ),
            emailSenderId: self::senderId($mailerConfig),
            fromName: self::profileValue($mailerConfig, 'from_name'),
            replyToEmail: self::profileValue($mailerConfig, 'reply_to'),
        );
    }

    private static function requiredServiceConfig(string $key): string
    {
        $value = config("services.textmagic.{$key}");

        if (! is_string($value) || mb_trim($value) === '') {
            throw new TransportException("Textmagic {$key} is not configured.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $mailerConfig
     */
    private static function senderId(array $mailerConfig): ?int
    {
        $senderId = $mailerConfig['textmagic']['sender_id'] ?? null;

        if ($senderId === null || $senderId === '') {
            return null;
        }

        return (int) $senderId;
    }

    /**
     * @param  array<string, mixed>  $mailerConfig
     */
    private static function profileValue(array $mailerConfig, string $key): ?string
    {
        $value = $mailerConfig['textmagic'][$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }
}
