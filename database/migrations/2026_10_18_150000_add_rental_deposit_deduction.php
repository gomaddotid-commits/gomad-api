<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table): void {
            $table->decimal('deposit_deducted_amount', 18, 2)->default(0)->after('deposit_returned_at');
            $table->decimal('deposit_refunded_amount', 18, 2)->default(0)->after('deposit_deducted_amount');
            $table->string('deposit_deduction_reason', 200)->nullable()->after('deposit_refunded_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table): void {
            $table->dropColumn([
                'deposit_deducted_amount',
                'deposit_refunded_amount',
                'deposit_deduction_reason',
            ]);
        });
    }
};
