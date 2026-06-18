<?php

declare(strict_types=1);

use App\Mail\Transports\TextmagicTransport;
use App\Services\Mail\TextmagicEmailService;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use TextMagic\Api\TextMagicApi;
use TextMagic\Models\CreateEmailCampaignRequest;
use TextMagic\Models\CreateEmailCampaignResponse;

afterEach(function (): void {
    Mockery::close();
});

it('creates a Textmagic email campaign from a Symfony email', function (): void {
    $api = Mockery::mock(TextMagicApi::class);

    $api
        ->shouldReceive('createEmailCampaign')
        ->once()
        ->with(Mockery::on(function (CreateEmailCampaignRequest $request): bool {
            return $request->getEmailSenderId() === 123
                && $request->getSubject() === 'Welcome'
                && $request->getMessage() === '<p>Hello</p>'
                && $request->getFromName() === 'Admin Team'
                && $request->getReplyToEmail() === 'reply@example.com'
                && $request->getRecipients()->getContactIds() === []
                && $request->getRecipients()->getEmails() === ['recipient@example.com']
                && $request->getRecipients()->getGroupIds() === [];
        }))
        ->andReturn(new CreateEmailCampaignResponse(['id' => 456]));

    $client = new TextmagicEmailService($api);

    $transport = new TextmagicTransport(
        client: $client,
        emailSenderId: 123,
        fromName: 'Admin Team',
        replyToEmail: 'reply@example.com',
    );

    $sentMessage = $transport->send(
        (new Email())
            ->from('admin@example.com')
            ->to('recipient@example.com')
            ->subject('Welcome')
            ->html('<p>Hello</p>'),
    );

    expect($sentMessage?->getMessageId())->toBe('456');
});

it('sends non-ASCII HTML body characters as numeric HTML entities', function (): void {
    $api = Mockery::mock(TextMagicApi::class);

    $api
        ->shouldReceive('createEmailCampaign')
        ->once()
        ->with(Mockery::on(function (CreateEmailCampaignRequest $request): bool {
            return $request->getSubject() === '=?UTF-8?B?Q2Fmw6kgcmVjaXRhbCDwn5mC?='
                && $request->getMessage() === '<p>Caf&#233; calltime &#128578; &amp; d&#233;j&#224; vu</p>';
        }))
        ->andReturn(new CreateEmailCampaignResponse(['id' => 789]));

    $client = new TextmagicEmailService($api);

    $transport = new TextmagicTransport(
        client: $client,
        emailSenderId: 123,
        fromName: 'Admin Team',
        replyToEmail: 'reply@example.com',
    );

    $transport->send(
        (new Email())
            ->from('admin@example.com')
            ->to('recipient@example.com')
            ->subject('Café recital 🙂')
            ->html('<p>Café calltime 🙂 &amp; déjà vu</p>'),
    );
});

it('uses the email reply-to address when one is set', function (): void {
    $api = Mockery::mock(TextMagicApi::class);

    $api
        ->shouldReceive('createEmailCampaign')
        ->once()
        ->with(Mockery::on(fn (CreateEmailCampaignRequest $request): bool => $request->getReplyToEmail() === 'message-reply@example.com'))
        ->andReturn(new CreateEmailCampaignResponse(['id' => 789]));

    $client = new TextmagicEmailService($api);

    $transport = new TextmagicTransport(
        client: $client,
        emailSenderId: 123,
        fromName: 'Admin Team',
        replyToEmail: 'profile-reply@example.com',
    );

    $transport->send(
        (new Email())
            ->from('admin@example.com')
            ->to('recipient@example.com')
            ->replyTo('message-reply@example.com')
            ->subject('Welcome')
            ->html('<p>Hello</p>'),
    );
});

it('converts plain text bodies to HTML', function (): void {
    $api = Mockery::mock(TextMagicApi::class);

    $api
        ->shouldReceive('createEmailCampaign')
        ->once()
        ->with(Mockery::on(fn (CreateEmailCampaignRequest $request): bool => $request->getMessage() === '<p>Hello<br />'."\n".'there</p>'))
        ->andReturn(new CreateEmailCampaignResponse(['id' => 789]));

    $client = new TextmagicEmailService($api);

    $transport = new TextmagicTransport(
        client: $client,
        emailSenderId: 123,
        fromName: null,
        replyToEmail: null,
    );

    $transport->send(
        (new Email())
            ->from('admin@example.com')
            ->to('recipient@example.com')
            ->subject('Welcome')
            ->text('Hello'."\n".'there'),
    );
});

it('fails clearly when a sender id is missing', function (): void {
    $transport = new TextmagicTransport(
        client: new TextmagicEmailService(Mockery::mock(TextMagicApi::class)),
        emailSenderId: null,
        fromName: null,
        replyToEmail: null,
    );

    expect(fn () => $transport->send(
        (new Email())
            ->from('admin@example.com')
            ->to('recipient@example.com')
            ->subject('Welcome')
            ->html('<p>Hello</p>'),
    ))->toThrow(TransportException::class, 'sender ID is not configured');
});

it('fails clearly for unsupported CC recipients', function (): void {
    $transport = new TextmagicTransport(
        client: new TextmagicEmailService(Mockery::mock(TextMagicApi::class)),
        emailSenderId: 123,
        fromName: null,
        replyToEmail: null,
    );

    expect(fn () => $transport->send(
        (new Email())
            ->from('admin@example.com')
            ->to('recipient@example.com')
            ->cc('copy@example.com')
            ->subject('Welcome')
            ->html('<p>Hello</p>'),
    ))->toThrow(TransportException::class, 'do not support CC');
});
