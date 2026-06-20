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
            $table->boolean('send_purchase_notification')->default(false)->after('include_productable_images');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('purchase_notification_requested')->default(false)->after('status');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('purchase_notification_queued_at')->nullable()->after('receipt_queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('purchase_notification_queued_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('purchase_notification_requested');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('send_purchase_notification');
        });
    }
};
