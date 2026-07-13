<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_line_items', function (Blueprint $table) {
            $table->index(['posted_at', 'folio_id'], 'folio_line_items_reporting_index');
        });

        Schema::table('payment_operations', function (Blueprint $table) {
            $table->index(
                ['created_at', 'status', 'type', 'payment_id'],
                'payment_operations_reporting_index',
            );
        });

        Schema::create('revenue_period_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->json('snapshot');
            $table->char('checksum', 64);
            $table->timestamp('closed_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['property_id', 'period_start', 'period_end', 'currency'],
                'revenue_period_closes_scope_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_period_closes');

        Schema::table('payment_operations', function (Blueprint $table) {
            $table->dropIndex('payment_operations_reporting_index');
        });

        Schema::table('folio_line_items', function (Blueprint $table) {
            $table->dropIndex('folio_line_items_reporting_index');
        });
    }
};
