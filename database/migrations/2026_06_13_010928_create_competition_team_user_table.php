<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('competition_team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_team_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['competition_team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_team_user');
    }
};
