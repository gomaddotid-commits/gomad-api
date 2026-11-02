<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'referral_code')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('referral_code', 20)->nullable()->unique()->after('status');
            });
        }

        if (! Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('referrer_user_id');
                $table->unsignedBigInteger('referee_user_id')->unique();
                $table->string('code', 20);
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('referee_promo_id')->nullable();
                $table->unsignedBigInteger('referrer_promo_id')->nullable();
                $table->timestamp('qualified_at')->nullable();
                $table->timestamp('rewarded_at')->nullable();
                $table->timestamps();

                $table->index(['referrer_user_id', 'status'], 'referrals_referrer_status_index');
                $table->foreign('referrer_user_id', 'referrals_referrer_foreign')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('referee_user_id', 'referrals_referee_foreign')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('referee_promo_id', 'referrals_referee_promo_foreign')->references('id')->on('promos')->nullOnDelete();
                $table->foreign('referrer_promo_id', 'referrals_referrer_promo_foreign')->references('id')->on('promos')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');

        if (Schema::hasColumn('users', 'referral_code')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(['referral_code']);
                $table->dropColumn('referral_code');
            });
        }
    }
};
