<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_product_requirement')) {
            Schema::create('course_product_requirement', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['product_id', 'course_id'], 'course_product_req_unique');
            });
        }

        if (! Schema::hasTable('competition_team_product_requirement')) {
            Schema::create('competition_team_product_requirement', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('competition_team_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['product_id', 'competition_team_id'], 'team_product_req_unique');
            });
        }

        if (! Schema::hasIndex(
            'course_product_requirement',
            ['product_id', 'course_id'],
            'unique',
        )) {
            Schema::table('course_product_requirement', function (Blueprint $table): void {
                $table->unique(['product_id', 'course_id'], 'course_product_req_unique');
            });
        }

        if (! Schema::hasIndex(
            'competition_team_product_requirement',
            ['product_id', 'competition_team_id'],
            'unique',
        )) {
            Schema::table('competition_team_product_requirement', function (Blueprint $table): void {
                $table->unique(['product_id', 'competition_team_id'], 'team_product_req_unique');
            });
        }

        if (Schema::hasColumn('products', 'requires_course_id')) {
            $now = now();

            DB::table('products')
                ->whereNotNull('requires_course_id')
                ->orderBy('id')
                ->each(function (object $product) use ($now): void {
                    DB::table('course_product_requirement')->insertOrIgnore([
                        'product_id' => $product->id,
                        'course_id' => $product->requires_course_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });

            Schema::table('products', function (Blueprint $table): void {
                $table->dropForeign(['requires_course_id']);
                $table->dropColumn('requires_course_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('requires_course_id')
                ->nullable()
                ->constrained('courses')
                ->nullOnDelete();
        });

        DB::table('course_product_requirement')
            ->select('product_id')
            ->selectRaw('MIN(course_id) AS course_id')
            ->groupBy('product_id')
            ->orderBy('product_id')
            ->each(function (object $requirement): void {
                DB::table('products')
                    ->where('id', $requirement->product_id)
                    ->update(['requires_course_id' => $requirement->course_id]);
            });

        Schema::dropIfExists('competition_team_product_requirement');
        Schema::dropIfExists('course_product_requirement');
    }
};
