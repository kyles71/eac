<?php

declare(strict_types=1);

namespace App\Mail;

use Filament\Forms\Components\RichEditor\RichContentRenderer;
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
                'content' => $this->formattedEmailBody(),
            ],
        );
    }

    protected function shouldSendManagedEmail(): bool
    {
        return app(MailManager::class)->isEnabled('handcrafted');
    }

    private function formattedEmailBody(): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", mb_trim($this->emailBody));

        if ($this->containsRichTextMarkup($body)) {
            return RichContentRenderer::make($body)->toHtml();
        }

        $body = preg_replace("/\n[ \t]*\n/u", "\n\n", $body) ?? $body;
        $paragraphs = preg_split("/\n{2,}/u", $body) ?: [];
        $html = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = mb_trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            $html .= '<p>'.nl2br(
                htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                false,
            ).'</p>';
        }

        return $html;
    }

    private function containsRichTextMarkup(string $body): bool
    {
        return preg_match('/<(?:p|h[2-3]|blockquote|ul|ol)\b/iu', $body) === 1;
    }
}
