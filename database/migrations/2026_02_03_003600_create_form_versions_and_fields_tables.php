<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status');
            $table->json('schema');
            $table->boolean('requires_signature')->default(false);
            $table->dateTime('valid_until')->nullable();
            $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'version']);
        });

        Schema::create('form_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('answer_type');
            $table->string('mapping')->nullable();
            $table->string('label')->nullable();
            $table->string('block_key', 80)->nullable();
            $table->string('sub_key')->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('form_versions');
    }
};
