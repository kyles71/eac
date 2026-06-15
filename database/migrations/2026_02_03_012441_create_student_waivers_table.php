<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('student_waivers', function (Blueprint $table) {
            $table->id();
            $table->text('medical_conditions')->nullable();
            $table->text('allergies')->nullable();
            $table->text('student_home_address')->nullable();
            $table->string('signer_relationship')->nullable();
            $table->text('past_injuries')->nullable();
            $table->text('medications')->nullable();
            $table->boolean('medical_release_consent')->nullable();
            $table->text('behavioral_notes')->nullable();
            $table->date('medical_release_signed_on')->nullable();
            $table->boolean('health_safety_policy_consent')->nullable();
            $table->date('health_safety_policy_signed_on')->nullable();
            $table->boolean('media_release_consent')->nullable();
            $table->date('media_release_signed_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_waivers');
    }
};
