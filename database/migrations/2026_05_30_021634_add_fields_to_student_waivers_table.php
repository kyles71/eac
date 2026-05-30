<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('student_waivers', function (Blueprint $table) {
            $table->string('student_name')->nullable();
            $table->date('student_birth_date')->nullable();
            $table->text('student_home_address')->nullable();
            $table->string('student_email')->nullable();
            $table->string('signer_name')->nullable();
            $table->string('signer_relationship')->nullable();
            $table->string('contact_phone')->nullable();
            $table->boolean('wants_text_updates')->nullable();
            $table->string('text_update_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('heard_about')->nullable();
            $table->text('past_injuries')->nullable();
            $table->text('medications')->nullable();
            $table->boolean('medical_release_consent')->nullable();
            $table->text('behavioral_notes')->nullable();
            $table->date('medical_release_signed_on')->nullable();
            $table->boolean('health_safety_policy_consent')->nullable();
            $table->date('health_safety_policy_signed_on')->nullable();
            $table->boolean('media_release_consent')->nullable();
            $table->date('media_release_signed_on')->nullable();
        });
    }
};
