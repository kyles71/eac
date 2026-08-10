<?php

declare(strict_types=1);

use App\Actions\Events\ReportLegacyPublicEventMedia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media')) {
            return;
        }

        app(ReportLegacyPublicEventMedia::class)->handle();
    }
};
