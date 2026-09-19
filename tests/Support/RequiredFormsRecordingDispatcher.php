<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Bus\Dispatcher;

final class RequiredFormsRecordingDispatcher implements Dispatcher
{
    /** @var list<object> */
    public array $commands = [];

    public function dispatch($command): mixed
    {
        $this->commands[] = $command;

        return null;
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        return null;
    }

    public function dispatchNow($command, $handler = null): mixed
    {
        return null;
    }

    public function dispatchAfterResponse($command, $handler = null): void {}

    public function chain($jobs = null): mixed
    {
        return null;
    }

    public function hasCommandHandler($command): bool
    {
        return false;
    }

    public function getCommandHandler($command): mixed
    {
        return null;
    }

    public function pipeThrough(array $pipes): static
    {
        return $this;
    }

    public function map(array $map): static
    {
        return $this;
    }
}
