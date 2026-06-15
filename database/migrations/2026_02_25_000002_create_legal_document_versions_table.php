<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('content');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['legal_document_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_versions');
    }
};
