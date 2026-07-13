<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway', 30)->nullable()->after('method');
            $table->string('gateway_payment_id')->nullable()->after('gateway');
            $table->string('idempotency_key')->nullable()->after('gateway_payment_id')->unique();
            $table->decimal('captured_amount', 12, 2)->default(0)->after('amount');
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('captured_amount');
            $table->string('settlement_status', 30)->default('pending')->after('status');
        });

        Schema::create('payment_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('gateway_transaction_id')->nullable();
            $table->string('status', 30)->default('processing');
            $table->json('gateway_response')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30);
            $table->string('event_id');
            $table->string('type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_operations');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn([
                'gateway',
                'gateway_payment_id',
                'idempotency_key',
                'captured_amount',
                'refunded_amount',
                'settlement_status',
            ]);
        });
    }
};
