<?php

declare(strict_types=1);

namespace App\Mail\Transports;

use App\Services\Mail\TextmagicEmailCampaignClient;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use TextMagic\ApiException;
use TextMagic\Models\CreateEmailCampaignRequest;
use TextMagic\Models\CreateEmailCampaignRequestRecipients;
use Throwable;

final class TextmagicTransport extends AbstractTransport
{
    public function __construct(
        private readonly TextmagicEmailCampaignClient $client,
        private readonly ?int $emailSenderId,
        private readonly ?string $fromName,
        private readonly ?string $replyToEmail,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'textmagic';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $this->ensureSupported($email);

        $request = new CreateEmailCampaignRequest([
            'emailSenderId' => $this->emailSenderId,
            'subject' => $this->subject($email),
            'message' => $this->htmlBody($email),
            'fromName' => $this->fromName,
            'replyToEmail' => $this->replyToEmail($email),
            'recipients' => new CreateEmailCampaignRequestRecipients([
                'contactIds' => [],
                'emails' => $this->recipientEmails($message),
                'groupIds' => [],
            ]),
        ]);

        try {
            $response = $this->client->createEmailCampaign($request);
        } catch (ApiException $exception) {
            throw new TransportException(
                sprintf('Request to Textmagic API failed. Reason: %s.', $exception->getMessage()),
                is_int($exception->getCode()) ? $exception->getCode() : 0,
                $exception,
            );
        } catch (InvalidArgumentException $exception) {
            throw new TransportException(
                sprintf('Textmagic email campaign request is invalid. Reason: %s.', $exception->getMessage()),
                0,
                $exception,
            );
        } catch (Throwable $exception) {
            throw new TransportException(
                sprintf('Textmagic email campaign could not be created. Reason: %s.', $exception->getMessage()),
                is_int($exception->getCode()) ? $exception->getCode() : 0,
                $exception,
            );
        }

        $message->setMessageId((string) $response->getId());
    }

    private function ensureSupported(Email $email): void
    {
        if ($this->emailSenderId === null || $this->emailSenderId < 1) {
            throw new TransportException('Textmagic email sender ID is not configured for this mailer.');
        }

        if ($email->getCc() !== []) {
            throw new TransportException('Textmagic email campaigns do not support CC recipients.');
        }

        if ($email->getBcc() !== []) {
            throw new TransportException('Textmagic email campaigns do not support BCC recipients.');
        }

        if ($email->getAttachments() !== []) {
            throw new TransportException('Textmagic email campaigns do not support attachments.');
        }

        if (count($email->getReplyTo()) > 1) {
            throw new TransportException('Textmagic email campaigns support only one reply-to address.');
        }
    }

    private function subject(Email $email): string
    {
        $subject = mb_trim((string) $email->getSubject());

        if ($subject === '') {
            throw new TransportException('Textmagic email campaigns require a subject.');
        }

        return $subject;
    }

    private function htmlBody(Email $email): string
    {
        $html = $this->bodyToString($email->getHtmlBody());

        if (is_string($html) && mb_trim($html) !== '') {
            return $html;
        }

        $text = $this->bodyToString($email->getTextBody());

        if (! is_string($text) || mb_trim($text) === '') {
            throw new TransportException('Textmagic email campaigns require an HTML or text body.');
        }

        return '<p>'.nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>';
    }

    /**
     * @return array<int, string>
     */
    private function recipientEmails(SentMessage $message): array
    {
        $recipients = array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getEnvelope()->getRecipients(),
        );

        $recipients = array_values(array_unique($recipients));

        if ($recipients === []) {
            throw new TransportException('Textmagic email campaigns require at least one recipient.');
        }

        return $recipients;
    }

    private function replyToEmail(Email $email): ?string
    {
        $replyTo = $email->getReplyTo();

        if ($replyTo !== []) {
            return $replyTo[0]->getAddress();
        }

        return $this->replyToEmail;
    }

    private function bodyToString(mixed $body): ?string
    {
        if ($body === null || is_string($body)) {
            return $body;
        }

        if (is_resource($body)) {
            rewind($body);

            return stream_get_contents($body) ?: null;
        }

        return (string) $body;
    }
}
