<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('managed_banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('render_location');
            $table->json('target_scopes')->nullable();
            $table->json('audiences');
            $table->string('tone');
            $table->string('icon')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_destination')->nullable();
            $table->string('cta_url')->nullable();
            $table->boolean('cta_new_tab')->default(false);
            $table->boolean('is_dismissible')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'published_at', 'expires_at']);
            $table->index('render_location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_banners');
    }
};
