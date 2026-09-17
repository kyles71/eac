<?php

declare(strict_types=1);

use App\Enums\TextMessageStatus;
use App\Services\TextMessages\TextmagicTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use TextMagic\Api\TextMagicApi;
use TextMagic\Configuration;

it('submits one exact SMS with a reference and never truncates', function (): void {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([new Response(201, [], json_encode([
        'id' => 101, 'type' => 'message', 'messageId' => 101, 'sessionId' => 201,
        'href' => '/api/v2/messages/101', 'bulkId' => null, 'scheduleId' => null, 'chatId' => null,
    ]))]));
    $handler->push(Middleware::history($history));
    $api = new TextMagicApi(new Client(['handler' => $handler]), (new Configuration())->setUsername('test')->setPassword('test'));
    $result = (new TextmagicTransport($api))->send('+13135550123', 'EAC: Classes cancelled.', '+13135550100', 77);
    $payload = json_decode((string) $history[0]['request']->getBody(), true);

    expect($result->status)->toBe(TextMessageStatus::Accepted)
        ->and($result->receipt['id'])->toBe(101)
        ->and($payload)->toMatchArray([
            'phones' => '13135550123', 'text' => 'EAC: Classes cancelled.',
            'from' => '+13135550100', 'referenceId' => 77, 'partsCount' => 6, 'cutExtra' => false, 'local' => false,
        ]);
});

it('classifies rejection and uncertain responses without leaking provider details', function (int $code, TextMessageStatus $status): void {
    $api = new TextMagicApi(new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response($code, ['Retry-After' => '90'], json_encode(['code' => $code, 'message' => 'secret-key and private phone'])),
    ]))]), new Configuration());
    $result = (new TextmagicTransport($api))->send('+13135550123', 'Notice', '+13135550100', 1);

    expect($result->status)->toBe($status)
        ->and($result->error)->not->toContain('secret-key', 'private phone');

    if ($code === 429) {
        expect($result->retryAfter)->toBe(90);
    }
})->with([
    [400, TextMessageStatus::Failed], [401, TextMessageStatus::Failed],
    [429, TextMessageStatus::Pending], [408, TextMessageStatus::Unknown], [500, TextMessageStatus::Unknown],
]);

it('treats connection timeouts and malformed success responses as unknown', function (): void {
    foreach ([
        new GuzzleHttp\Exception\ConnectException('timeout', new GuzzleHttp\Psr7\Request('POST', 'https://example.test')),
        new Response(201, [], 'not valid json'),
    ] as $response) {
        $api = new TextMagicApi(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]), new Configuration());
        $result = (new TextmagicTransport($api))->send('+13135550123', 'Notice', '+13135550100', 1);
        expect($result->status)->toBe(TextMessageStatus::Unknown);
    }
});
