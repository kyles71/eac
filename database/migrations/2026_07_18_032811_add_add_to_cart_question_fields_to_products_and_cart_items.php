<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('ask_purchaser_questions_when_adding_to_cart')
                ->default(false)
                ->after('send_purchase_notification');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->json('question_answers')
                ->nullable()
                ->after('custom_gift_card_amount');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('question_answers');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ask_purchaser_questions_when_adding_to_cart');
        });
    }
};
