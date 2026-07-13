<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('room_type_id')->constrained()->restrictOnDelete();
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->foreignId('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
            $table->index(['room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'status']);
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn('checked_in_at');
            $table->dropConstrainedForeignId('room_id');
        });
    }
};
