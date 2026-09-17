<?php

declare(strict_types=1);

namespace App\Data\TextMessages;

use App\Enums\TextMessageStatus;

final readonly class TextMessageSubmission
{
    /** @param array<string, int|string|null> $receipt */
    public function __construct(
        public TextMessageStatus $status,
        public array $receipt = [],
        public ?string $error = null,
        public ?int $retryAfter = null,
    ) {}
}
