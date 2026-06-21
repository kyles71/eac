<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $hasOverlongStaffBio = DB::table('users')
            ->whereNotNull('staff_bio')
            ->pluck('staff_bio')
            ->contains(fn (string $staffBio): bool => mb_strlen($staffBio) > 500);

        if ($hasOverlongStaffBio) {
            throw new RuntimeException('Cannot limit users.staff_bio to 500 characters while longer values exist.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('staff_bio', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->longText('staff_bio')->nullable()->change();
        });
    }
};
