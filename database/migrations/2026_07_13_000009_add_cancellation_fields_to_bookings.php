<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('nightly_rate', 12, 2)->nullable()->after('special_requests');
            $table->string('cancellation_policy', 50)->default('standard')->after('nightly_rate');
            $table->json('cancellation_policy_snapshot')->nullable()->after('cancellation_policy');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_policy_snapshot');
            $table->foreignId('cancelled_by')
                ->nullable()
                ->after('cancelled_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->decimal('cancellation_penalty', 12, 2)
                ->nullable()
                ->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'cancellation_policy',
                'cancellation_policy_snapshot',
                'nightly_rate',
                'cancelled_at',
                'cancellation_penalty',
            ]);
        });
    }
};
