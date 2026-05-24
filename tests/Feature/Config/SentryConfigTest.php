<?php

declare(strict_types=1);

use Sentry\Breadcrumb;
use Sentry\CheckInStatus;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\Integration\IntegrationInterface;
use Sentry\MonitorConfig;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

it('keeps Sentry disabled until a DSN is configured', function (): void {
    expect(config('sentry.dsn'))->toBeNull()
        ->and(config('sentry.sample_rate'))->toBe(1.0)
        ->and(config('sentry.traces_sample_rate'))->toBeNull();
});

it('reports Laravel exceptions through the Sentry hub', function (): void {
    $originalHub = SentrySdk::getCurrentHub();

    $capturingHub = new class implements HubInterface
    {
        /** @var list<Throwable> */
        public array $capturedExceptions = [];

        public function getClient(): ?ClientInterface
        {
            return null;
        }

        public function getLastEventId(): ?EventId
        {
            return null;
        }

        public function pushScope(): Scope
        {
            return new Scope;
        }

        public function popScope(): bool
        {
            return true;
        }

        public function withScope(callable $callback): mixed
        {
            return $callback(new Scope);
        }

        public function configureScope(callable $callback): void
        {
            $callback(new Scope);
        }

        public function bindClient(ClientInterface $client): void {}

        public function captureMessage(string $message, ?Severity $level = null, ?EventHint $hint = null): ?EventId
        {
            return EventId::generate();
        }

        public function captureException(Throwable $exception, ?EventHint $hint = null): ?EventId
        {
            $this->capturedExceptions[] = $exception;

            return EventId::generate();
        }

        public function captureEvent(Event $event, ?EventHint $hint = null): ?EventId
        {
            return EventId::generate();
        }

        public function captureLastError(?EventHint $hint = null): ?EventId
        {
            return EventId::generate();
        }

        public function addBreadcrumb(Breadcrumb $breadcrumb): bool
        {
            return true;
        }

        public function captureCheckIn(string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): ?string
        {
            return 'check-in-id';
        }

        public function getIntegration(string $className): ?IntegrationInterface
        {
            return null;
        }

        public function startTransaction(TransactionContext $context, array $customSamplingContext = []): Transaction
        {
            throw new BadMethodCallException('Transactions are not needed for this test.');
        }

        public function getTransaction(): ?Transaction
        {
            return null;
        }

        public function getSpan(): ?Span
        {
            return null;
        }

        public function setSpan(?Span $span): HubInterface
        {
            return $this;
        }
    };

    SentrySdk::setCurrentHub($capturingHub);

    try {
        $exception = new RuntimeException('Sentry integration smoke test.');

        report($exception);

        expect($capturingHub->capturedExceptions)
            ->toHaveCount(1)
            ->and($capturingHub->capturedExceptions[0])
            ->toBe($exception);
    } finally {
        SentrySdk::setCurrentHub($originalHub);
    }
});
