<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('saved_report_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_key');
            $table->string('name');
            $table->string('visibility')->default('private');
            $table->json('state');
            $table->timestamps();

            $table->unique(['user_id', 'report_key', 'name'], 'saved_report_views_owner_report_name_unique');
            $table->index(['report_key', 'visibility']);
        });

        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_key');
            $table->string('format');
            $table->string('status')->default('pending');
            $table->json('state');
            $table->string('disk')->default('local');
            $table->string('path')->nullable();
            $table->string('file_name');
            $table->unsignedInteger('total_rows')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('saved_report_views');
    }
};
