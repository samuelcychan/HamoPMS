<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('room_type_id')
                ->nullable()
                ->after('property_id')
                ->constrained()
                ->restrictOnDelete();
            $table->index(['room_type_id', 'status', 'check_in', 'check_out']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['room_type_id', 'status', 'check_in', 'check_out']);
            $table->dropConstrainedForeignId('room_type_id');
        });
    }
};
