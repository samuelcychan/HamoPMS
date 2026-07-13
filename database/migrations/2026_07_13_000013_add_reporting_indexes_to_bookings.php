<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['property_id', 'check_in', 'status'], 'bookings_property_arrivals_index');
            $table->index(['property_id', 'check_out', 'status'], 'bookings_property_departures_index');
            $table->index(
                ['property_id', 'status', 'checked_in_at', 'checked_out_at'],
                'bookings_property_in_house_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_property_arrivals_index');
            $table->dropIndex('bookings_property_departures_index');
            $table->dropIndex('bookings_property_in_house_index');
        });
    }
};
