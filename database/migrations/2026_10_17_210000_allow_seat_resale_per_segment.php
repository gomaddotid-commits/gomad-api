<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seats are no longer exclusive for the whole trip: a seat vacated at an
     * intermediate stop can be sold again for a later segment. Uniqueness is
     * enforced per segment inside CreateTravelBooking while the schedule row
     * is locked, so the database only needs lookup indexes.
     *
     * The old unique index also served the foreign key on schedule_id, so a
     * replacement index must exist before it is dropped.
     */
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table): void {
            $table->index('schedule_id', 'booking_passenger_schedule_id_index');
            $table->dropUnique('booking_passenger_schedule_seat_unique');
            $table->index(['schedule_id', 'seat_number'], 'booking_passenger_schedule_seat_index');
        });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table): void {
            $table->dropIndex('booking_passenger_schedule_seat_index');
            $table->unique(['schedule_id', 'seat_number'], 'booking_passenger_schedule_seat_unique');
            $table->dropIndex('booking_passenger_schedule_id_index');
        });
    }
};
