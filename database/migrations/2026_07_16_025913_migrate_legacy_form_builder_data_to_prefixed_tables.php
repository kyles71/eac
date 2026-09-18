<?php

declare(strict_types=1);

use App\Forms\Migration\LegacyFormMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(LegacyFormMigration::class)->migrate();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The legacy form conversion is intentionally forward-only. Restore the mandatory pre-deployment database snapshot to roll it back.',
        );
    }
};
