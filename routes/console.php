<?php

declare(strict_types=1);

use App\Actions\Store\ProcessInstallments;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $processInstallments = app(ProcessInstallments::class);
    $result = $processInstallments->handle();

    info('ProcessInstallments completed.', $result);
})->daily()->name('process-installments')->description('Process due and retryable payment plan installments');
