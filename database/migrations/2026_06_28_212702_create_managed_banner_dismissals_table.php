<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('managed_banner_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('managed_banner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at');
            $table->timestamps();

            $table->unique(['managed_banner_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_banner_dismissals');
    }
};
