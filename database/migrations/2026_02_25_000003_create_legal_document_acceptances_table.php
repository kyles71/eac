<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('acceptable');
            $table->timestamp('accepted_at');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->unique(
                ['legal_document_version_id', 'acceptable_type', 'acceptable_id'],
                'legal_document_acceptance_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_acceptances');
    }
};
