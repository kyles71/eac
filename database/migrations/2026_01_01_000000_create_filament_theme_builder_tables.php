<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filament_theme_builder_themes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('settings');
            $table->timestamps();
        });

        Schema::create('filament_theme_builder_panel_themes', function (Blueprint $table): void {
            $table->id();
            $table->string('panel_id')->unique();
            $table->foreignId('theme_id')
                ->constrained('filament_theme_builder_themes')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filament_theme_builder_panel_themes');
        Schema::dropIfExists('filament_theme_builder_themes');
    }
};
