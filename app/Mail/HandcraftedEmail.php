<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kyle\FilamentMailManager\Data\RenderedEmail;
use Kyle\FilamentMailManager\Mail\ManagedMailable;
use Kyle\FilamentMailManager\MailManager;

final class HandcraftedEmail extends ManagedMailable implements ShouldQueue
{
    public function __construct(
        public readonly string $emailSubject,
        public readonly string $emailBody,
    ) {}

    protected function renderManagedEmail(): RenderedEmail
    {
        return app(MailManager::class)->render(
            emailTypeKey: 'handcrafted',
            tokens: [
                'email.subject' => $this->emailSubject,
            ],
            slots: [
                'content' => $this->emailBody,
            ],
        );
    }

    protected function shouldSendManagedEmail(): bool
    {
        return app(MailManager::class)->isEnabled('handcrafted');
    }
}
