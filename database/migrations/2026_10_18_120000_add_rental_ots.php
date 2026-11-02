<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table): void {
            $table->string('payment_mode', 20)->default('online')->after('rental_type');
            $table->string('ots_fee_transaction_id', 26)->nullable()->after('payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table): void {
            $table->dropColumn(['payment_mode', 'ots_fee_transaction_id']);
        });
    }
};
