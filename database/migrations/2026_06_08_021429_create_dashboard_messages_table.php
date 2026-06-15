<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_messages', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->string('audience');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['audience', 'published_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_messages');
    }
};
