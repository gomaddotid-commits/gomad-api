<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->foreignId('rental_id')->nullable()->after('booking_id')
                ->constrained('rentals')->restrictOnDelete();
            $table->index(['rental_id', 'status']);
        });

        Schema::table('rentals', function (Blueprint $table): void {
            $table->foreignId('payment_intent_id')->nullable()->after('request_hash')
                ->constrained('payment_intents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_intent_id');
        });

        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropIndex(['rental_id', 'status']);
            $table->dropConstrainedForeignId('rental_id');
        });
    }
};
