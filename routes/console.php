<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('installments:process')
    ->daily()
    ->name('process-installments')
    ->description('Process due and retryable payment plan installments');
