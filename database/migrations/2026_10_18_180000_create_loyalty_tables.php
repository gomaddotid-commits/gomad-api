<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'points_cached')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedInteger('points_cached')->default(0)->after('referral_code');
            });
        }

        if (! Schema::hasTable('loyalty_events')) {
            Schema::create('loyalty_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('kind', 30);
                $table->unsignedInteger('points');
                $table->string('reference_type', 30);
                $table->unsignedBigInteger('reference_id');
                $table->timestamp('occurred_at');
                $table->timestamps();

                // Satu transaksi hanya menambah poin sekali, walau webhook datang berulang.
                $table->unique(['kind', 'reference_type', 'reference_id'], 'loyalty_events_reference_unique');
                $table->index(['user_id', 'occurred_at'], 'loyalty_events_user_index');
                $table->foreign('user_id', 'loyalty_events_user_foreign')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_events');

        if (Schema::hasColumn('users', 'points_cached')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('points_cached');
            });
        }
    }
};
