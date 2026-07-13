<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('special_requests')->nullable()->after('notes');
        });

        Schema::create('booking_modifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before');
            $table->json('after');
            $table->decimal('rate_difference', 12, 2);
            $table->timestamps();

            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_modifications');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('special_requests');
        });
    }
};
