<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;
use TextMagic\Api\TextMagicApi;
use TextMagic\Models\CreateEmailCampaignRequest;
use TextMagic\Models\CreateEmailCampaignResponse;

final class TextmagicEmailCampaignClient
{
    public function __construct(
        private readonly TextMagicApi $api,
    ) {}

    public function createEmailCampaign(CreateEmailCampaignRequest $request): CreateEmailCampaignResponse
    {
        $response = $this->api->createEmailCampaign($request);

        if (! $response instanceof CreateEmailCampaignResponse) {
            throw new RuntimeException('Textmagic returned an unexpected email campaign response.');
        }

        return $response;
    }
}
