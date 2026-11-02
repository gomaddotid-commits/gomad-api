<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->decimal('rental_deposit', 18, 2)->default(0)->after('rental_driver_daily_fee');
        });

        Schema::table('rentals', function (Blueprint $table): void {
            $table->timestamp('deposit_returned_at')->nullable()->after('deposit_status');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table): void {
            $table->dropColumn('deposit_returned_at');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn('rental_deposit');
        });
    }
};
