<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\TextMessages\TextMessageSubmission;

interface TextMessageTransport
{
    public function validateConfiguration(): void;

    public function send(string $phone, string $body, string $sender, int $reference): TextMessageSubmission;
}
