<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SentEmails\Pages;

use App\Filament\Admin\Resources\SentEmails\SentEmailResource;
use FinityLabs\FinMail\Resources\SentEmailResource\Pages\ListSentEmails as BaseListSentEmails;

final class ListSentEmails extends BaseListSentEmails
{
    protected static string $resource = SentEmailResource::class;
}
