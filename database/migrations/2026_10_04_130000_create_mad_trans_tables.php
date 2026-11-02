<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('origin_city', 120);
            $table->string('destination_city', 120);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('route_stops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('stop_order');
            $table->string('city_name', 120);
            $table->string('name', 160);
            $table->string('pickup_zone', 500)->nullable();
            $table->timestamps();
            $table->unique(['route_id', 'stop_order']);
            $table->index(['route_id', 'city_name']);
        });

        Schema::create('route_pricing', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->foreignId('destination_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->decimal('price', 18, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['route_id', 'origin_stop_id', 'destination_stop_id'], 'route_price_segment_unique');
            $table->index(['route_id', 'is_active']);
        });

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('plate_number', 30);
            $table->json('seat_layout');
            $table->unsignedSmallInteger('capacity');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['business_id', 'plate_number']);
        });

        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('business_members')->nullOnDelete();
            $table->date('departure_date');
            $table->time('departure_time');
            $table->string('travel_class', 40);
            $table->decimal('price_per_seat', 18, 2);
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('allow_cod')->default(false);
            $table->json('payment_methods');
            $table->string('risk_status', 20)->default('ok')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'departure_date', 'status']);
            $table->index(['route_id', 'departure_date', 'status']);
            $table->index(['driver_id', 'departure_date']);
        });

        Schema::create('schedule_stops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->unsignedSmallInteger('stop_order');
            $table->string('pickup_zone', 500)->nullable();
            $table->timestamps();
            $table->unique(['schedule_id', 'stop_order']);
            $table->unique(['schedule_id', 'route_stop_id']);
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('schedule_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('origin_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->foreignId('destination_stop_id')->constrained('route_stops')->restrictOnDelete();
            $table->foreignId('route_pricing_id')->constrained('route_pricing')->restrictOnDelete();
            $table->string('pickup_address', 1000)->nullable();
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->string('destination_address', 1000)->nullable();
            $table->decimal('destination_latitude', 10, 7)->nullable();
            $table->decimal('destination_longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('total_passengers');
            $table->decimal('base_price', 18, 2);
            $table->decimal('service_fee', 18, 2);
            $table->decimal('platform_fee', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('ppn_amount', 18, 2);
            $table->decimal('total_price', 18, 2);
            $table->string('payment_method', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->restrictOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
            $table->index(['schedule_id', 'status', 'expires_at']);
        });

        Schema::create('booking_passengers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('phone', 30)->nullable();
            $table->string('seat_number', 12)->nullable();
            $table->string('seat_snapshot', 12);
            $table->timestamp('seat_released_at')->nullable();
            $table->timestamp('boarded_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->foreignId('cod_fee_transaction_id')->nullable()
                ->constrained('ledger_transactions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['schedule_id', 'seat_number'], 'booking_passenger_schedule_seat_unique');
            $table->index(['booking_id', 'schedule_id']);
        });

        Schema::create('driver_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('business_members')->restrictOnDelete();
            $table->string('status', 20)->default('assigned')->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['schedule_id', 'driver_id']);
            $table->index(['driver_id', 'status']);
        });

        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->foreignId('booking_id')->nullable()->after('order_id')
                ->constrained('bookings')->restrictOnDelete();
            $table->index(['booking_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropIndex(['booking_id', 'purpose']);
            $table->dropConstrainedForeignId('booking_id');
        });
        Schema::dropIfExists('driver_assignments');
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('schedule_stops');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('route_pricing');
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('routes');
    }
};
