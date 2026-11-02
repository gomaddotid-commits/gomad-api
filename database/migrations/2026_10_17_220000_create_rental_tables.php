<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->decimal('rental_daily_price', 18, 2)->nullable()->after('capacity');
            $table->decimal('rental_driver_daily_fee', 18, 2)->nullable()->after('rental_daily_price');
        });

        Schema::create('rentals', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('business_members')->nullOnDelete();
            $table->string('rental_type', 20);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->unsignedSmallInteger('days');
            $table->decimal('price', 18, 2);
            $table->decimal('driver_fee', 18, 2)->default(0);
            $table->decimal('service_fee', 18, 2);
            $table->decimal('platform_fee', 18, 2);
            $table->decimal('ppn_amount', 18, 2);
            $table->decimal('total_price', 18, 2);
            $table->decimal('deposit_amount', 18, 2)->default(0);
            $table->string('deposit_status', 20)->default('none');
            $table->string('status', 20)->default('pending')->index();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['vehicle_id', 'start_at', 'end_at']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rentals');

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn(['rental_daily_price', 'rental_driver_daily_fee']);
        });
    }
};
