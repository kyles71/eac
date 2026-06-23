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
        Schema::create('credit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('source');
            $table->unsignedInteger('initial_amount');
            $table->unsignedInteger('remaining_amount');
            $table->string('description');
            $table->string('restricted_to_product_type')->nullable();
            $table->boolean('has_product_restrictions')->default(false);
            $table->date('expires_on')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'remaining_amount']);
        });

        Schema::create('credit_grant_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_grant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['credit_grant_id', 'product_id']);
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->foreignId('credit_grant_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('performed_by_user_id')
                ->nullable()
                ->after('credit_grant_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        $now = now();

        DB::table('users')
            ->where('credit_balance', '>', 0)
            ->orderBy('id')
            ->each(function (object $user) use ($now): void {
                $grantId = DB::table('credit_grants')->insertGetId([
                    'user_id' => $user->id,
                    'initial_amount' => $user->credit_balance,
                    'remaining_amount' => $user->credit_balance,
                    'description' => 'Opening store credit balance',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('credit_transactions')->insert([
                    'user_id' => $user->id,
                    'credit_grant_id' => $grantId,
                    'amount' => $user->credit_balance,
                    'type' => 'OpeningBalance',
                    'description' => 'Opening store credit balance',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        DB::table('restricted_credits')
            ->orderBy('id')
            ->each(function (object $restrictedCredit) use ($now): void {
                $giftCard = DB::table('gift_cards')->find($restrictedCredit->gift_card_id);
                $giftCardType = DB::table('gift_card_types')->find($restrictedCredit->gift_card_type_id);

                $grantId = DB::table('credit_grants')->insertGetId([
                    'user_id' => $restrictedCredit->user_id,
                    'source_type' => 'App\\Models\\GiftCard',
                    'source_id' => $restrictedCredit->gift_card_id,
                    'initial_amount' => $restrictedCredit->balance,
                    'remaining_amount' => $restrictedCredit->balance,
                    'description' => $giftCard === null
                        ? 'Opening limited use credit balance'
                        : 'Opening balance from gift card '.$giftCard->code,
                    'restricted_to_product_type' => $giftCardType?->restricted_to_product_type,
                    'created_at' => $restrictedCredit->created_at ?? $now,
                    'updated_at' => $now,
                ]);

                DB::table('credit_transactions')->insert([
                    'user_id' => $restrictedCredit->user_id,
                    'credit_grant_id' => $grantId,
                    'amount' => $restrictedCredit->balance,
                    'type' => 'OpeningBalance',
                    'description' => $giftCard === null
                        ? 'Opening limited use credit balance'
                        : 'Opening balance from gift card '.$giftCard->code,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $productIds = DB::table('gift_card_type_product')
                    ->where('gift_card_type_id', $restrictedCredit->gift_card_type_id)
                    ->pluck('product_id');

                foreach ($productIds as $productId) {
                    DB::table('credit_grant_product')->insert([
                        'credit_grant_id' => $grantId,
                        'product_id' => $productId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if ($productIds->isNotEmpty()) {
                    DB::table('credit_grants')
                        ->where('id', $grantId)
                        ->update(['has_product_restrictions' => true]);
                }
            });

        Schema::dropIfExists('restricted_credits');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credit_balance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('credit_balance')->default(0);
        });

        Schema::create('restricted_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_card_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('balance');
            $table->timestamps();
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performed_by_user_id');
            $table->dropConstrainedForeignId('credit_grant_id');
        });

        Schema::dropIfExists('credit_grant_product');
        Schema::dropIfExists('credit_grants');
    }
};
