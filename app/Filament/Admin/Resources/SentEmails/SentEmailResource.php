<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SentEmails;

use App\Filament\Admin\Resources\SentEmails\Pages\ListSentEmails;
use App\Filament\Shared\Schemas\SentEmailPreviewSchema;
use Filament\Tables\Table;
use FinityLabs\FinMail\Resources\SentEmailResource\SentEmailResource as BaseSentEmailResource;
use Kyle\FilamentMailManager\Filament\Clusters\MailManager\MailManagerCluster;
use Kyle\FilamentMailManager\MailManagerAuthorization;

final class SentEmailResource extends BaseSentEmailResource
{
    protected static ?string $cluster = MailManagerCluster::class;

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        $columns = $table->getColumns();
        unset($columns['sender.name']);

        $table->columns(array_values($columns));

        $table->getAction('view')?->schema(SentEmailPreviewSchema::schema());
        $table->getAction('resend')?->visible(fn (): bool => MailManagerAuthorization::canManage());

        return $table;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSentEmails::route('/'),
        ];
    }
}
