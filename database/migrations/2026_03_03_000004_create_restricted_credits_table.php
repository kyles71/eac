<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('restricted_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_card_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('balance');
            $table->timestamps();
        });
    }
};
