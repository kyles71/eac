<?php

declare(strict_types=1);

namespace App\Services\TextMessages;

use App\Contracts\TextMessageTransport;
use App\Data\TextMessages\TextMessageSubmission;
use App\Enums\TextMessageStatus;
use GuzzleHttp\Client;
use Illuminate\Validation\ValidationException;
use TextMagic\Api\TextMagicApi;
use TextMagic\ApiException;
use TextMagic\Configuration;
use TextMagic\Models\SendMessageRequest;
use TextMagic\Models\SendMessageResponse;
use Throwable;

final class TextmagicTransport implements TextMessageTransport
{
    public function __construct(private ?TextMagicApi $api = null) {}

    public function validateConfiguration(): void
    {
        foreach (['username', 'api_key'] as $key) {
            if (blank(config("services.textmagic.{$key}"))) {
                throw ValidationException::withMessages(['body' => 'Textmagic credentials are not configured.']);
            }
        }
    }

    public function send(string $phone, string $body, string $sender, int $reference): TextMessageSubmission
    {
        try {
            $response = $this->client()->sendMessage(new SendMessageRequest([
                'phones' => mb_ltrim($phone, '+'),
                'text' => $body,
                'from' => $sender,
                'referenceId' => $reference,
                'partsCount' => 6,
                'cutExtra' => false,
                'local' => false,
            ]));

            if (! $response instanceof SendMessageResponse || ! $response->getId()) {
                return new TextMessageSubmission(TextMessageStatus::Unknown, error: 'The provider response could not be confirmed. Review in Textmagic before resending.');
            }

            return new TextMessageSubmission(TextMessageStatus::Accepted, [
                'id' => $response->getId(),
                'type' => $response->getType(),
                'message_id' => $response->getMessageId(),
                'session_id' => $response->getSessionId(),
                'reference' => $reference,
            ]);
        } catch (ApiException $exception) {
            $code = $exception->getCode();

            if ($code === 429) {
                $headers = array_change_key_case($exception->getResponseHeaders() ?? [], CASE_LOWER);
                $value = $headers['retry-after'][0] ?? 30;

                return new TextMessageSubmission(TextMessageStatus::Pending, error: 'The provider rate limit was reached.', retryAfter: is_numeric($value) ? max(30, (int) $value) : 60);
            }

            if ($code >= 400 && $code < 500 && $code !== 408) {
                return new TextMessageSubmission(TextMessageStatus::Failed, error: "Textmagic rejected the request (HTTP {$code}). Check the recipient, account balance, credentials, and sender in Textmagic.");
            }

            return new TextMessageSubmission(TextMessageStatus::Unknown, error: 'Textmagic acceptance could not be confirmed. Review in Textmagic before resending.');
        } catch (Throwable) {
            return new TextMessageSubmission(TextMessageStatus::Unknown, error: 'The provider request was interrupted or its response was invalid. Review in Textmagic before resending.');
        }
    }

    private function client(): TextMagicApi
    {
        return $this->api ??= new TextMagicApi(
            new Client([
                'connect_timeout' => min(5, max(1, (int) config('text-messages.connect_timeout', 5))),
                'timeout' => min(20, max(1, (int) config('text-messages.timeout', 20))),
            ]),
            (new Configuration())
                ->setUsername((string) config('services.textmagic.username'))
                ->setPassword((string) config('services.textmagic.api_key')),
        );
    }
}
